<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\DataTransfer\JournalLineDraft;
use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Enums\JournalEntryType;
use App\Domains\Accounting\Exceptions\AccountingException;
use App\Domains\Accounting\Exceptions\ImmutableEntryException;
use App\Domains\Accounting\Exceptions\InvalidAccountException;
use App\Domains\Accounting\Exceptions\UnbalancedEntryException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\AccountingPeriod;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\JournalEntryLine;
use App\Domains\Identity\Services\AuditLogger;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Motor contable de partida doble.
 *
 * Único punto de escritura del libro diario. Ventas, compras, inventario,
 * bancos, caja y activos fijos entregan un JournalDraft y este servicio decide
 * si se convierte en partida. Ningún otro código inserta en `journal_entries`.
 *
 * Garantías que ofrece, todas verificadas antes del commit:
 *   1. Total debe = total haber, comparado con bcmath sobre strings.
 *   2. Al menos dos líneas, cada una con importe positivo de un solo lado.
 *   3. Cuentas de la empresa activa, imputables y activas.
 *   4. Fecha dentro de un período abierto.
 *   5. Un documento no genera dos partidas vigentes.
 *   6. Una partida contabilizada no se modifica; se anula o se revierte.
 */
final class AccountingEngine
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly PeriodService $periods,
        private readonly DocumentSeriesService $series,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Crea la partida y la contabiliza en un solo paso. Es la vía que usan los
     * módulos automáticos: una factura confirmada no pasa por borrador.
     */
    public function post(JournalDraft $draft): JournalEntry
    {
        return DB::transaction(function () use ($draft): JournalEntry {
            $entry = $this->persist($draft, JournalEntryStatus::Draft);

            return $this->postEntry($entry);
        });
    }

    /**
     * Guarda la partida sin contabilizarla. El borrador no tiene folio ni
     * afecta saldos, pero ya se valida que las cuentas existan y que el período
     * esté abierto: no tiene sentido capturar sobre un período cerrado.
     */
    public function saveDraft(JournalDraft $draft): JournalEntry
    {
        return DB::transaction(fn (): JournalEntry => $this->persist($draft, JournalEntryStatus::Draft));
    }

    /**
     * Reemplaza el contenido de un borrador.
     */
    public function updateDraft(JournalEntry $entry, JournalDraft $draft): JournalEntry
    {
        if (! $entry->isDraft()) {
            throw ImmutableEntryException::cannotEdit($entry);
        }

        return DB::transaction(function () use ($entry, $draft): JournalEntry {
            $period = $this->periods->openPeriodFor($draft->date());
            $this->guardAccounts($draft);

            $entry->forceFill([
                'accounting_period_id' => $period->id,
                'branch_id' => $draft->branchId(),
                'date' => $draft->date()->toDateString(),
                'type' => $draft->type(),
                'concept' => $draft->concept(),
                'reference' => $draft->reference(),
                'currency_code' => $draft->currency(),
                'exchange_rate' => $draft->exchangeRate(),
                'total_debit' => $draft->totalDebit()->toString(),
                'total_credit' => $draft->totalCredit()->toString(),
            ])->save();

            $entry->lines()->delete();
            $this->insertLines($entry, $draft->lines());

            $this->audit->log('updated', $entry, module: 'accounting');

            return $entry->refresh();
        });
    }

    public function deleteDraft(JournalEntry $entry): void
    {
        if (! $entry->isDraft()) {
            throw ImmutableEntryException::cannotEdit($entry);
        }

        DB::transaction(function () use ($entry): void {
            $this->audit->log('deleted', $entry, oldValues: $entry->only([
                'date', 'concept', 'total_debit', 'total_credit',
            ]), module: 'accounting');

            $entry->lines()->delete();
            $entry->delete();
        });
    }

    /**
     * Contabiliza un borrador existente: le asigna folio, lo marca como
     * contabilizado y suma sus importes a los saldos.
     */
    public function postEntry(JournalEntry $entry): JournalEntry
    {
        if ($entry->isPosted()) {
            throw ImmutableEntryException::alreadyPosted($entry);
        }

        if ($entry->isVoided()) {
            throw ImmutableEntryException::cannotEdit($entry);
        }

        return DB::transaction(function () use ($entry): JournalEntry {
            $entry->load('lines');

            $this->guardLineCount($entry->lines->count());
            $this->guardBalance(
                Money::sum($entry->lines->map(fn ($line) => $line->debitAmount())->all()),
                Money::sum($entry->lines->map(fn ($line) => $line->creditAmount())->all()),
            );

            $period = $this->periods->openPeriodFor($entry->date);
            $this->guardNoExistingPostingFor($entry);

            $number = $this->series->nextJournalNumber($period->fiscalYear->name);

            $entry->forceFill([
                'number' => $number,
                'accounting_period_id' => $period->id,
                'status' => JournalEntryStatus::Posted,
                'posted_at' => now(),
                'posted_by' => Auth::id(),
            ])->save();

            $this->applyToBalances($entry, $period, sign: 1);

            $this->audit->log('posted', $entry, newValues: [
                'number' => $number,
                'total_debit' => $entry->total_debit,
                'total_credit' => $entry->total_credit,
            ], module: 'accounting');

            return $entry->refresh();
        });
    }

    /**
     * Anula una partida contabilizada dentro de su propio período.
     *
     * El registro no se borra: queda marcado como anulado, conserva sus líneas
     * y su folio, y su efecto se resta de los saldos. Solo procede mientras el
     * período siga abierto; si ya se cerró, la corrección debe ser una
     * reversión, porque un período cerrado no puede cambiar de saldo.
     */
    public function void(JournalEntry $entry, string $reason): JournalEntry
    {
        if ($entry->isDraft()) {
            throw ImmutableEntryException::cannotVoidDraft($entry);
        }

        if ($entry->isVoided()) {
            throw ImmutableEntryException::alreadyVoided($entry);
        }

        if (trim($reason) === '') {
            throw new AccountingException('La anulación exige indicar un motivo.');
        }

        return DB::transaction(function () use ($entry, $reason): JournalEntry {
            $period = $entry->period;

            if (! $period->acceptsPostings()) {
                throw new AccountingException(sprintf(
                    'El período %s ya está cerrado, así que la partida %s no puede anularse. '
                    .'Genera una reversión en un período abierto.',
                    $period->name,
                    $entry->number,
                ));
            }

            $this->applyToBalances($entry, $period, sign: -1);

            $entry->forceFill([
                'status' => JournalEntryStatus::Voided,
                'voided_at' => now(),
                'voided_by' => Auth::id(),
                'void_reason' => $reason,
            ])->save();

            $this->audit->log('voided', $entry, oldValues: [
                'status' => JournalEntryStatus::Posted->value,
            ], newValues: [
                'status' => JournalEntryStatus::Voided->value,
            ], reason: $reason, module: 'accounting');

            return $entry->refresh();
        });
    }

    /**
     * Genera una partida nueva con los importes al lado contrario.
     *
     * Es la corrección válida cuando el período original ya se cerró: la
     * partida original permanece intacta y el ajuste queda fechado en un
     * período abierto, que es lo que espera una auditoría.
     */
    public function reverse(JournalEntry $entry, string $reason, DateTimeInterface|string|null $date = null): JournalEntry
    {
        if (! $entry->isPosted()) {
            throw new AccountingException(
                "Solo se pueden revertir partidas contabilizadas; {$entry->number} no lo está."
            );
        }

        if (trim($reason) === '') {
            throw new AccountingException('La reversión exige indicar un motivo.');
        }

        return DB::transaction(function () use ($entry, $reason, $date): JournalEntry {
            $entry->load('lines');

            $reversalDate = $date === null
                ? CarbonImmutable::now()->startOfDay()
                : CarbonImmutable::parse($date)->startOfDay();

            // Cada línea se reconstruye con el importe al lado contrario.
            $inverted = JournalDraft::on($reversalDate, "Reversión de {$entry->number}: {$reason}")
                ->ofType(JournalEntryType::Reversal)
                ->inBranch($entry->branch_id)
                ->withReference($entry->number);

            foreach ($entry->lines as $line) {
                $inverted->addLine($line->isDebit()
                    ? JournalLineDraft::credit($line->account_id, $line->debitAmount(), $line->description, $line->branch_id, $line->partner_type, $line->partner_id, $line->document_ref)
                    : JournalLineDraft::debit($line->account_id, $line->creditAmount(), $line->description, $line->branch_id, $line->partner_type, $line->partner_id, $line->document_ref));
            }

            $reversal = $this->persist($inverted, JournalEntryStatus::Draft);
            $reversal->forceFill(['reversal_of_id' => $entry->id])->save();

            $reversal = $this->postEntry($reversal);

            $this->audit->log('reversed', $entry, newValues: [
                'reversal_entry_id' => $reversal->id,
                'reversal_number' => $reversal->number,
            ], reason: $reason, module: 'accounting');

            return $reversal;
        });
    }

    /**
     * Inserta la partida y sus líneas sin contabilizarla.
     */
    private function persist(JournalDraft $draft, JournalEntryStatus $status): JournalEntry
    {
        $this->guardLineCount(count($draft->lines()));
        $this->guardBalance($draft->totalDebit(), $draft->totalCredit());
        $this->guardAccounts($draft);
        $this->guardNoExistingPostingForDraft($draft);

        $period = $this->periods->openPeriodFor($draft->date());

        $entry = new JournalEntry;
        $entry->forceFill([
            'company_id' => $this->context->idOrFail(),
            'branch_id' => $draft->branchId(),
            'accounting_period_id' => $period->id,
            'number' => null,
            'date' => $draft->date()->toDateString(),
            'type' => $draft->type(),
            'concept' => $draft->concept(),
            'reference' => $draft->reference(),
            'source_type' => $draft->sourceType(),
            'source_id' => $draft->sourceId(),
            'currency_code' => $draft->currency(),
            'exchange_rate' => $draft->exchangeRate(),
            'total_debit' => $draft->totalDebit()->toString(),
            'total_credit' => $draft->totalCredit()->toString(),
            'status' => $status,
            'created_by' => Auth::id(),
        ])->save();

        $this->insertLines($entry, $draft->lines());

        return $entry;
    }

    /**
     * @param  array<int, JournalLineDraft>  $lines
     */
    private function insertLines(JournalEntry $entry, array $lines): void
    {
        $number = 1;

        foreach ($lines as $line) {
            // forceFill: `company_id` se hereda de la partida y no es asignable
            // en masa, para que ningún formulario pueda enviarlo.
            $model = new JournalEntryLine;
            $model->forceFill([
                'journal_entry_id' => $entry->id,
                'company_id' => $entry->company_id,
                'account_id' => $line->accountId,
                'branch_id' => $line->branchId ?? $entry->branch_id,
                'line_number' => $number++,
                'description' => $line->description,
                'debit' => $line->debit->toString(),
                'credit' => $line->credit->toString(),
                'partner_type' => $line->partnerType,
                'partner_id' => $line->partnerId,
                'document_ref' => $line->documentRef,
            ])->save();
        }
    }

    private function guardLineCount(int $count): void
    {
        if ($count < 2) {
            throw UnbalancedEntryException::tooFewLines($count);
        }
    }

    private function guardBalance(Money $debit, Money $credit): void
    {
        if ($debit->isZero() && $credit->isZero()) {
            throw UnbalancedEntryException::empty();
        }

        if (! $debit->equals($credit)) {
            throw UnbalancedEntryException::make($debit, $credit);
        }
    }

    /**
     * Comprueba en una sola consulta que todas las cuentas de la partida
     * pertenezcan a la empresa activa, sean imputables y estén activas.
     */
    private function guardAccounts(JournalDraft $draft): void
    {
        $ids = $draft->accountIds();

        /** @var array<int, Account> $accounts */
        $accounts = Account::query()->whereKey($ids)->get()->keyBy('id')->all();

        foreach ($ids as $id) {
            if (! isset($accounts[$id])) {
                // El scope global ya limita a la empresa activa, así que una
                // cuenta ausente es de otra empresa o no existe.
                throw InvalidAccountException::notFound($id);
            }
        }

        foreach ($draft->lines() as $line) {
            $account = $accounts[$line->accountId];

            if (! $account->is_active) {
                throw InvalidAccountException::inactive($account);
            }

            if (! $account->is_postable) {
                throw InvalidAccountException::notPostable($account);
            }

            if ($account->requires_partner && $line->partnerId === null) {
                throw InvalidAccountException::requiresPartner($account);
            }

            if ($account->requires_branch && ($line->branchId ?? $draft->branchId()) === null) {
                throw InvalidAccountException::requiresBranch($account);
            }
        }
    }

    /**
     * Idempotencia. El índice único sobre la columna generada `source_key` es
     * la garantía real; esta comprobación existe para dar un mensaje legible en
     * vez de un error de restricción de la base de datos.
     */
    private function guardNoExistingPostingForDraft(JournalDraft $draft): void
    {
        if ($draft->sourceType() === null || $draft->sourceId() === null) {
            return;
        }

        $existing = JournalEntry::query()
            ->where('source_type', $draft->sourceType())
            ->where('source_id', $draft->sourceId())
            ->where('status', '!=', JournalEntryStatus::Voided)
            ->first();

        if ($existing !== null) {
            throw new AccountingException(sprintf(
                'El documento %s #%d ya tiene la partida %s. Anúlala antes de volver a contabilizarlo.',
                $draft->sourceType(),
                $draft->sourceId(),
                $existing->number ?? '(borrador)',
            ));
        }
    }

    private function guardNoExistingPostingFor(JournalEntry $entry): void
    {
        if ($entry->source_type === null || $entry->source_id === null) {
            return;
        }

        $existing = JournalEntry::query()
            ->where('source_type', $entry->source_type)
            ->where('source_id', $entry->source_id)
            ->where('status', JournalEntryStatus::Posted)
            ->whereKeyNot($entry->id)
            ->exists();

        if ($existing) {
            throw new AccountingException(sprintf(
                'El documento %s #%d ya está contabilizado.',
                $entry->source_type,
                $entry->source_id,
            ));
        }
    }

    /**
     * Suma (o resta, al anular) los importes de la partida al acumulado de cada
     * cuenta en el período.
     *
     * Un solo INSERT ... ON DUPLICATE KEY UPDATE con incremento: leer, sumar en
     * PHP y volver a escribir perdería actualizaciones cuando dos partidas de
     * la misma cuenta se contabilizan a la vez.
     */
    private function applyToBalances(JournalEntry $entry, AccountingPeriod $period, int $sign): void
    {
        $entry->loadMissing('lines');

        /** @var array<int, array{debit: Money, credit: Money}> $totals */
        $totals = [];

        foreach ($entry->lines as $line) {
            $accountId = $line->account_id;

            $totals[$accountId] ??= ['debit' => Money::zero(), 'credit' => Money::zero()];
            $totals[$accountId]['debit'] = $totals[$accountId]['debit']->plus($line->debitAmount());
            $totals[$accountId]['credit'] = $totals[$accountId]['credit']->plus($line->creditAmount());
        }

        if ($totals === []) {
            return;
        }

        $placeholders = [];
        $bindings = [];

        foreach ($totals as $accountId => $amounts) {
            $debit = $sign < 0 ? $amounts['debit']->negated() : $amounts['debit'];
            $credit = $sign < 0 ? $amounts['credit']->negated() : $amounts['credit'];

            $placeholders[] = '(?, ?, ?, ?, ?, NOW(), NOW())';
            array_push(
                $bindings,
                $entry->company_id,
                $accountId,
                $period->id,
                $debit->toString(),
                $credit->toString(),
            );
        }

        $values = implode(', ', $placeholders);

        DB::statement(
            <<<SQL
            INSERT INTO account_balances
                (company_id, account_id, accounting_period_id, period_debit, period_credit, created_at, updated_at)
            VALUES {$values} AS incoming
            ON DUPLICATE KEY UPDATE
                period_debit = account_balances.period_debit + incoming.period_debit,
                period_credit = account_balances.period_credit + incoming.period_credit,
                updated_at = NOW()
            SQL,
            $bindings,
        );
    }
}
