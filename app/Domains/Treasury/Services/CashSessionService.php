<?php

declare(strict_types=1);

namespace App\Domains\Treasury\Services;

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Enums\AccountMappingKey;
use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Accounting\Services\AccountMappingService;
use App\Domains\Accounting\Services\DocumentSeriesService;
use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Treasury\Enums\CashSessionStatus;
use App\Domains\Treasury\Exceptions\TreasuryException;
use App\Domains\Treasury\Models\CashSession;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Apertura, cierre y arqueo de caja.
 *
 * ## Qué se contabiliza y qué no
 *
 * **Abrir no genera partida.** El fondo de caja ya está en la cuenta contable
 * desde que se puso ahí; declararlo al abrir es decirle al sistema con cuánto
 * arranca el turno, no mover dinero.
 *
 * **Cerrar solo genera partida si hay diferencia.** Si el conteo coincide con
 * el libro no hay nada que asentar. Si no coincide, la diferencia se contabiliza
 * **siempre**, sin preguntar y sin permitir «cuadrar» la caja a mano: un arqueo
 * que se ajusta para que dé cero no es un arqueo.
 *
 * Un faltante carga la cuenta de sobrantes y faltantes y abona caja —el dinero
 * ya no está—; un sobrante hace lo contrario.
 */
final class CashSessionService
{
    public const SERIES = 'cash_session';

    public function __construct(
        private readonly CompanyContext $context,
        private readonly AccountingEngine $engine,
        private readonly AccountMappingService $mappings,
        private readonly DocumentSeriesService $series,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function open(array $data): CashSession
    {
        return DB::transaction(function () use ($data): CashSession {
            $account = Account::query()->findOrFail($data['account_id']);

            if (! $account->is_cash_equivalent) {
                throw TreasuryException::accountNotCash($account);
            }

            $float = Money::of((string) ($data['opening_float'] ?? '0'));

            if ($float->isNegative()) {
                throw TreasuryException::negativeAmount();
            }

            $session = new CashSession;

            try {
                $session->forceFill([
                    'company_id' => $this->context->idOrFail(),
                    'branch_id' => $data['branch_id'],
                    'account_id' => $account->id,
                    'number' => $this->series->next(self::SERIES, '*', $data['branch_id'], 'CAJ-'),
                    'opened_at' => now(),
                    'opened_by' => Auth::id(),
                    'opening_float' => $float->toString(),
                    'status' => CashSessionStatus::Open,
                    'notes' => $data['notes'] ?? null,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                // Lo garantiza el índice sobre la columna generada; aquí solo
                // se traduce a un mensaje que se entienda.
                throw TreasuryException::cashSessionAlreadyOpen($account);
            }

            $this->audit->log('opened', $session, newValues: [
                'number' => $session->number,
                'opening_float' => $session->opening_float,
            ], module: 'treasury');

            return $session->refresh();
        });
    }

    /**
     * Cierra la sesión con el efectivo contado.
     */
    public function close(CashSession $session, Money $counted, ?string $notes = null): CashSession
    {
        if (! $session->isOpen()) {
            throw TreasuryException::cashSessionNotOpen($session);
        }

        if ($counted->isNegative()) {
            throw TreasuryException::negativeAmount();
        }

        return DB::transaction(function () use ($session, $counted, $notes): CashSession {
            $expected = $this->expectedAmount($session);
            $difference = $counted->minus($expected);

            $session->forceFill([
                'closed_at' => now(),
                'closed_by' => Auth::id(),
                'counted_amount' => $counted->toString(),
                'expected_amount' => $expected->toString(),
                'difference' => $difference->toString(),
                'status' => CashSessionStatus::Closed,
                'notes' => $notes ?? $session->notes,
            ])->save();

            if (! $difference->isZero()) {
                $this->engine->post($this->buildJournalDraft($session, $difference));
            }

            $this->audit->log('closed', $session, newValues: [
                'counted' => $counted->toString(),
                'expected' => $expected->toString(),
                'difference' => $difference->toString(),
            ], module: 'treasury');

            return $session->refresh();
        });
    }

    /**
     * Lo que el libro dice que debería haber en la caja ahora mismo.
     *
     * Fondo inicial más el neto de las partidas registradas en esa cuenta y
     * esa sucursal mientras la sesión ha estado abierta. Se filtra por el
     * instante de registro de la partida y no por su fecha contable, porque un
     * turno dura horas y la fecha solo tiene día.
     */
    public function expectedAmount(CashSession $session): Money
    {
        $row = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $session->company_id)
            ->where('l.account_id', $session->account_id)
            ->where('e.status', JournalEntryStatus::Posted->value)
            ->where('e.created_at', '>=', $session->opened_at)
            ->when(
                $session->closed_at !== null,
                fn ($q) => $q->where('e.created_at', '<=', $session->closed_at),
            )
            // La partida de la diferencia del propio arqueo no cuenta: se
            // registra después de calcular lo esperado, y si entrara aquí la
            // caja parecería cuadrar sola.
            ->where(fn ($q) => $q
                ->whereNull('e.source_type')
                ->orWhere('e.source_type', '!=', CashSession::SOURCE_TYPE)
                ->orWhere('e.source_id', '!=', $session->id))
            ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
            ->first();

        $movements = Money::of((string) $row->debit)->minus(Money::of((string) $row->credit));

        return $session->openingFloat()->plus($movements);
    }

    /**
     * Partida de la diferencia de arqueo.
     */
    private function buildJournalDraft(CashSession $session, Money $difference): JournalDraft
    {
        $overShort = $this->mappings->resolveId(AccountMappingKey::TreasuryCashOverShort);
        $amount = $difference->absolute();

        $draft = JournalDraft::on(
            CarbonImmutable::parse($session->closed_at)->toDateString(),
            'Arqueo de caja '.$session->number.($difference->isNegative() ? ' — faltante' : ' — sobrante'),
        )
            ->inBranch($session->branch_id)
            ->withReference($session->number)
            ->fromDocument(CashSession::SOURCE_TYPE, $session->id);

        if ($difference->isNegative()) {
            // Falta dinero: sale de caja y se reconoce la pérdida.
            $draft->debit($overShort, $amount, 'Faltante de caja')
                ->credit($session->account_id, $amount, 'Ajuste por arqueo');
        } else {
            $draft->debit($session->account_id, $amount, 'Ajuste por arqueo')
                ->credit($overShort, $amount, 'Sobrante de caja');
        }

        return $draft;
    }
}
