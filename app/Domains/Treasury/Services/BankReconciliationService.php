<?php

declare(strict_types=1);

namespace App\Domains\Treasury\Services;

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\JournalEntryLine;
use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Treasury\Enums\ReconciliationStatus;
use App\Domains\Treasury\Exceptions\TreasuryException;
use App\Domains\Treasury\Models\BankAccount;
use App\Domains\Treasury\Models\BankReconciliation;
use App\Domains\Treasury\Models\BankReconciliationLine;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Conciliación bancaria.
 *
 * ## De dónde salen los cuatro números
 *
 * Los cuatro importes de la conciliación se derivan de **una sola fuente**: las
 * líneas del libro sobre la cuenta bancaria, marcadas o sin marcar.
 *
 *     saldo en libros       = todas las líneas hasta la fecha de corte
 *     depósitos en tránsito = cargos sin marcar   (entró al libro, no al banco)
 *     cheques pendientes    = abonos sin marcar   (salió del libro, no del banco)
 *
 * Con esas definiciones la identidad se cumple por construcción:
 *
 *     extracto + tránsito − pendientes = libros
 *
 * porque «extracto» debería ser la suma de lo marcado, y lo marcado es todo
 * menos lo no marcado. Cuando no se cumple, la diferencia es exactamente lo que
 * falta por explicar, que es la información útil.
 *
 * ## Por qué los cheques pendientes no salen de la tabla de cheques
 *
 * Existe `checks` y sabe qué cheques no ha pagado el banco, pero **no entra en
 * la aritmética**. Un cheque girado ya produjo su abono en el libro; contarlo
 * otra vez desde su propia tabla lo restaría dos veces. La tabla de cheques
 * sirve para que el usuario vea *cuáles* son —número, beneficiario— mientras la
 * conciliación sigue cuadrando con un solo origen.
 */
final class BankReconciliationService
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly BankAccountService $bankAccounts,
        private readonly AuditLogger $audit,
    ) {}

    public function open(BankAccount $bankAccount, DateTimeInterface|string $cutoff, Money $statementBalance, ?string $notes = null): BankReconciliation
    {
        return DB::transaction(function () use ($bankAccount, $cutoff, $statementBalance, $notes): BankReconciliation {
            $reconciliation = new BankReconciliation;
            $reconciliation->forceFill([
                'company_id' => $this->context->idOrFail(),
                'bank_account_id' => $bankAccount->id,
                'cutoff_date' => CarbonImmutable::parse($cutoff)->toDateString(),
                'statement_balance' => $statementBalance->toString(),
                'status' => ReconciliationStatus::Draft,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ])->save();

            return $this->recalculate($reconciliation->refresh());
        });
    }

    /**
     * Partidas que esta conciliación puede tocar: las de la cuenta bancaria
     * hasta la fecha de corte, salvo las que ya se llevó otra conciliación.
     *
     * @return Collection<int, JournalEntryLine>
     */
    public function items(BankReconciliation $reconciliation): Collection
    {
        $reconciliation->loadMissing('bankAccount');

        return JournalEntryLine::query()
            ->with(['entry:id,number,date,concept,reference,status'])
            ->where('account_id', $reconciliation->bankAccount->account_id)
            ->whereHas('entry', fn ($q) => $q
                ->where('status', JournalEntryStatus::Posted)
                ->where('date', '<=', $reconciliation->cutoff_date->toDateString()))
            ->whereNotExists(function ($query) use ($reconciliation): void {
                $query->select(DB::raw(1))
                    ->from('bank_reconciliation_lines as rl')
                    ->whereColumn('rl.journal_entry_line_id', 'journal_entry_lines.id')
                    ->where('rl.bank_reconciliation_id', '!=', $reconciliation->id);
            })
            ->get()
            ->sortBy([
                fn (JournalEntryLine $line) => $line->entry->date->toDateString(),
                fn (JournalEntryLine $line) => $line->id,
            ])
            ->values();
    }

    /**
     * Ids de las líneas ya marcadas en esta conciliación.
     *
     * @return array<int, int>
     */
    public function markedIds(BankReconciliation $reconciliation): array
    {
        return $reconciliation->lines()->pluck('journal_entry_line_id')->all();
    }

    public function mark(BankReconciliation $reconciliation, int $lineId, DateTimeInterface|string|null $clearedOn = null): BankReconciliation
    {
        $this->guardDraft($reconciliation);

        return DB::transaction(function () use ($reconciliation, $lineId, $clearedOn): BankReconciliation {
            $line = JournalEntryLine::query()->with('entry')->findOrFail($lineId);

            $reconciliation->loadMissing('bankAccount');

            if ($line->account_id !== $reconciliation->bankAccount->account_id) {
                throw TreasuryException::lineNotFromBankAccount();
            }

            if ($line->entry->date->gt($reconciliation->cutoff_date)) {
                throw TreasuryException::lineAfterCutoff();
            }

            $existing = BankReconciliationLine::query()
                ->where('journal_entry_line_id', $lineId)
                ->first();

            if ($existing !== null && $existing->bank_reconciliation_id !== $reconciliation->id) {
                throw TreasuryException::lineAlreadyReconciled();
            }

            if ($existing === null) {
                $mark = new BankReconciliationLine;
                $mark->forceFill([
                    'bank_reconciliation_id' => $reconciliation->id,
                    'company_id' => $reconciliation->company_id,
                    'journal_entry_line_id' => $lineId,
                    'cleared_on' => $clearedOn === null ? null : CarbonImmutable::parse($clearedOn)->toDateString(),
                ])->save();
            }

            return $this->recalculate($reconciliation);
        });
    }

    public function unmark(BankReconciliation $reconciliation, int $lineId): BankReconciliation
    {
        $this->guardDraft($reconciliation);

        return DB::transaction(function () use ($reconciliation, $lineId): BankReconciliation {
            $reconciliation->lines()->where('journal_entry_line_id', $lineId)->delete();

            return $this->recalculate($reconciliation);
        });
    }

    /**
     * Marca de golpe todas las partidas hasta la fecha de corte.
     *
     * Es lo que se hace cuando el extracto coincide con el libro sin
     * excepciones, que es el caso habitual de una empresa pequeña.
     */
    public function markAll(BankReconciliation $reconciliation): BankReconciliation
    {
        $this->guardDraft($reconciliation);

        return DB::transaction(function () use ($reconciliation): BankReconciliation {
            $marked = $this->markedIds($reconciliation);

            foreach ($this->items($reconciliation) as $line) {
                if (in_array($line->id, $marked, strict: true)) {
                    continue;
                }

                $mark = new BankReconciliationLine;
                $mark->forceFill([
                    'bank_reconciliation_id' => $reconciliation->id,
                    'company_id' => $reconciliation->company_id,
                    'journal_entry_line_id' => $line->id,
                ])->save();
            }

            return $this->recalculate($reconciliation);
        });
    }

    /**
     * Recalcula y guarda los cuatro importes.
     */
    public function recalculate(BankReconciliation $reconciliation): BankReconciliation
    {
        $reconciliation->loadMissing('bankAccount');

        $bookBalance = $this->bankAccounts->bookBalance(
            $reconciliation->bankAccount,
            $reconciliation->cutoff_date,
        );

        [$inTransit, $outstanding] = $this->unmarkedTotals($reconciliation);

        // extracto + tránsito − pendientes − libros. Cero si todo cuadra.
        $difference = $reconciliation->statementBalance()
            ->plus($inTransit)
            ->minus($outstanding)
            ->minus($bookBalance);

        $reconciliation->forceFill([
            'book_balance' => $bookBalance->toString(),
            'deposits_in_transit' => $inTransit->toString(),
            'outstanding_checks' => $outstanding->toString(),
            'difference' => $difference->toString(),
        ])->save();

        return $reconciliation->refresh();
    }

    public function close(BankReconciliation $reconciliation): BankReconciliation
    {
        $this->guardDraft($reconciliation);

        return DB::transaction(function () use ($reconciliation): BankReconciliation {
            $reconciliation = $this->recalculate($reconciliation);

            if (! $reconciliation->isBalanced()) {
                throw TreasuryException::reconciliationDoesNotBalance($reconciliation);
            }

            $reconciliation->forceFill([
                'status' => ReconciliationStatus::Closed,
                'closed_at' => now(),
                'closed_by' => Auth::id(),
            ])->save();

            $this->audit->log('closed', $reconciliation, newValues: [
                'cutoff_date' => $reconciliation->cutoff_date->toDateString(),
                'statement_balance' => $reconciliation->statement_balance,
                'book_balance' => $reconciliation->book_balance,
            ], module: 'treasury');

            return $reconciliation->refresh();
        });
    }

    public function reopen(BankReconciliation $reconciliation, string $reason): BankReconciliation
    {
        if (! $reconciliation->isClosed()) {
            throw TreasuryException::reconciliationNotClosed($reconciliation);
        }

        if (trim($reason) === '') {
            throw new TreasuryException('Hay que indicar por qué se reabre la conciliación.');
        }

        return DB::transaction(function () use ($reconciliation, $reason): BankReconciliation {
            $reconciliation->forceFill([
                'status' => ReconciliationStatus::Draft,
                'closed_at' => null,
                'closed_by' => null,
            ])->save();

            $this->audit->log('reopened', $reconciliation, reason: $reason, module: 'treasury');

            return $this->recalculate($reconciliation->refresh());
        });
    }

    public function delete(BankReconciliation $reconciliation): void
    {
        $this->guardDraft($reconciliation);

        DB::transaction(function () use ($reconciliation): void {
            $reconciliation->lines()->delete();
            $reconciliation->delete();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Interno
    |--------------------------------------------------------------------------
    */

    /**
     * Totales de lo que el banco **todavía no ha reflejado**: cargos por un
     * lado, abonos por otro.
     *
     * «No marcado» significa no marcado en **ninguna** conciliación cuya fecha
     * de corte llegue hasta esta. El saldo de un extracto es acumulativo: lo
     * que el banco cobró en enero también está dentro del saldo de febrero,
     * así que si solo se descontara lo marcado en la conciliación actual, todo
     * lo de enero reaparecería en febrero como depósito en tránsito y la
     * identidad no cerraría nunca a partir del segundo mes.
     *
     * @return array{0: Money, 1: Money}
     */
    private function unmarkedTotals(BankReconciliation $reconciliation): array
    {
        $cutoff = $reconciliation->cutoff_date->toDateString();

        $row = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $reconciliation->company_id)
            ->where('l.account_id', $reconciliation->bankAccount->account_id)
            ->where('e.status', JournalEntryStatus::Posted->value)
            ->where('e.date', '<=', $cutoff)
            ->whereNotExists(function ($query) use ($cutoff): void {
                $query->select(DB::raw(1))
                    ->from('bank_reconciliation_lines as rl')
                    ->join('bank_reconciliations as r', 'r.id', '=', 'rl.bank_reconciliation_id')
                    ->whereColumn('rl.journal_entry_line_id', 'l.id')
                    ->where('r.cutoff_date', '<=', $cutoff);
            })
            ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
            ->first();

        return [
            Money::of((string) $row->debit),
            Money::of((string) $row->credit),
        ];
    }

    private function guardDraft(BankReconciliation $reconciliation): void
    {
        if (! $reconciliation->isDraft()) {
            throw TreasuryException::reconciliationNotDraft($reconciliation);
        }
    }
}
