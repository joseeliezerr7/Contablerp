<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\DataTransfer\StatementRow;
use App\Domains\Accounting\Enums\AccountMappingKey;
use App\Domains\Accounting\Enums\AccountNature;
use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Enums\JournalEntryType;
use App\Domains\Accounting\Enums\PeriodStatus;
use App\Domains\Accounting\Exceptions\AccountingException;
use App\Domains\Accounting\Models\AccountingPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Identity\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Cierre del ejercicio fiscal.
 *
 * Genera dos partidas de tipo «cierre», fechadas el último día del ejercicio:
 *
 *   1. Cancela las cuentas de resultado contra Resumen de Resultados.
 *   2. Traslada el saldo del Resumen de Resultados a Utilidades Retenidas.
 *
 * **No genera partida de apertura, y es deliberado.** En este sistema el libro
 * es continuo: las cuentas de balance acumulan su saldo a lo largo del tiempo y
 * el balance general las lee desde el origen. Una partida de apertura que
 * volviera a registrar esos saldos los contaría dos veces. La apertura solo
 * tiene sentido cuando cada ejercicio es un libro separado, que no es el caso.
 * Al crear el ejercicio siguiente no hay que hacer nada: los saldos ya están.
 */
final class ClosingService
{
    public function __construct(
        private readonly FinancialStatementService $statements,
        private readonly AccountMappingService $mappings,
        private readonly AccountingEngine $engine,
        private readonly PeriodService $periods,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{entries: array<int, JournalEntry>, net_profit: Money}
     */
    public function closeFiscalYear(FiscalYear $year, ?int $userId = null): array
    {
        $this->guardCanClose($year);

        return DB::transaction(function () use ($year, $userId): array {
            $result = $this->statements->incomeStatement($year->starts_on, $year->ends_on);
            $netProfit = $result['net_profit'];

            $entries = [];
            $summaryAccount = $this->mappings->resolve(AccountMappingKey::ClosingIncomeSummary);
            $retainedAccount = $this->mappings->resolve(AccountMappingKey::ClosingRetainedEarnings);

            /** @var array<int, StatementRow> $resultRows */
            $resultRows = array_merge(
                $result['income']->all(),
                $result['cost']->all(),
                $result['expense']->all(),
            );

            $rowsToClose = array_filter(
                $resultRows,
                fn (StatementRow $row) => ! $row->closing->isZero(),
            );

            if ($rowsToClose !== []) {
                $entries[] = $this->postResultClosing($year, $rowsToClose, $summaryAccount->id);
            }

            if (! $netProfit->isZero()) {
                $entries[] = $this->postProfitTransfer(
                    $year,
                    $summaryAccount->id,
                    $retainedAccount->id,
                    $netProfit,
                );
            }

            $this->closeRemainingPeriods($year, $userId);

            $year->forceFill([
                'status' => FiscalYearStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $userId,
            ])->save();

            $this->audit->log('closed', $year, newValues: [
                'net_profit' => $netProfit->toString(),
                'entries' => array_map(fn (JournalEntry $e) => $e->number, $entries),
            ], module: 'accounting');

            return ['entries' => $entries, 'net_profit' => $netProfit];
        });
    }

    /**
     * Comprobación previa para la pantalla: qué impide cerrar el ejercicio.
     *
     * @return array<int, string>
     */
    public function blockers(FiscalYear $year): array
    {
        $problems = [];

        if ($year->status !== FiscalYearStatus::Open) {
            $problems[] = "El ejercicio {$year->name} ya está ".mb_strtolower($year->status->label()).'.';

            return $problems;
        }

        // Consulta directa y no `$year->periods()`: esa relación ya trae un
        // orderBy ascendente y añadirle orderByDesc no lo sustituye, devolvería
        // el primer período en vez del último.
        $last = AccountingPeriod::query()
            ->where('fiscal_year_id', $year->id)
            ->orderByDesc('number')
            ->first();

        if ($last === null) {
            $problems[] = 'El ejercicio no tiene períodos.';

            return $problems;
        }

        $openBefore = $year->periods()
            ->where('number', '<', $last->number)
            ->where('status', PeriodStatus::Open)
            ->orderBy('number')
            ->get();

        // Un mensaje por período llenaría la pantalla con once líneas casi
        // idénticas al empezar el ejercicio.
        if ($openBefore->count() === 1) {
            $problems[] = "El período {$openBefore->first()->name} sigue abierto.";
        } elseif ($openBefore->count() > 1) {
            $problems[] = sprintf(
                'Quedan %d períodos abiertos, de %s a %s. Ciérralos en orden antes del ejercicio.',
                $openBefore->count(),
                $openBefore->first()->name,
                $openBefore->last()->name,
            );
        }

        if (! $last->acceptsPostings()) {
            $problems[] = sprintf(
                'El último período (%s) está %s; debe estar abierto para recibir la partida de cierre.',
                $last->name,
                mb_strtolower($last->status->label()),
            );
        }

        $drafts = JournalEntry::query()
            ->whereIn('accounting_period_id', $year->periods()->pluck('id'))
            ->where('status', 'draft')
            ->count();

        if ($drafts > 0) {
            $problems[] = "Hay {$drafts} partida(s) en borrador dentro del ejercicio.";
        }

        return $problems;
    }

    private function guardCanClose(FiscalYear $year): void
    {
        $problems = $this->blockers($year);

        if ($problems !== []) {
            throw new AccountingException(
                'No se puede cerrar el ejercicio: '.implode(' ', $problems)
            );
        }
    }

    /**
     * @param  array<int, StatementRow>  $rows
     */
    private function postResultClosing(FiscalYear $year, array $rows, int $summaryAccountId): JournalEntry
    {
        $draft = JournalDraft::on($year->ends_on, "Cierre de resultados del ejercicio {$year->name}")
            ->ofType(JournalEntryType::Closing)
            ->withReference("CIERRE-{$year->name}");

        $summaryBalance = Money::zero();

        foreach ($rows as $row) {
            $balance = $row->closing;

            if ($balance->isZero()) {
                continue;
            }

            $isCreditNature = $row->nature === AccountNature::Credit;

            // Cancelar una cuenta es moverla al lado contrario de su naturaleza.
            // Con saldo anómalo (negativo) el lado se invierte, que es lo que
            // ocurre en las contra-cuentas con movimiento neto al revés.
            $postToDebit = $isCreditNature ? $balance->isPositive() : $balance->isNegative();

            $postToDebit
                ? $draft->debit($row->accountId, $balance->absolute(), "Cierre {$row->code}")
                : $draft->credit($row->accountId, $balance->absolute(), "Cierre {$row->code}");

            // Los ingresos suman al resultado; costos y gastos restan.
            $summaryBalance = $isCreditNature
                ? $summaryBalance->plus($balance)
                : $summaryBalance->minus($balance);
        }

        // La contrapartida de todo el bloque va al Resumen de Resultados.
        if ($summaryBalance->isPositive()) {
            $draft->credit($summaryAccountId, $summaryBalance, 'Resultado del ejercicio');
        } elseif ($summaryBalance->isNegative()) {
            $draft->debit($summaryAccountId, $summaryBalance->negated(), 'Resultado del ejercicio');
        }

        return $this->engine->post($draft);
    }

    private function postProfitTransfer(
        FiscalYear $year,
        int $summaryAccountId,
        int $retainedAccountId,
        Money $netProfit,
    ): JournalEntry {
        $draft = JournalDraft::on($year->ends_on, "Traslado del resultado del ejercicio {$year->name}")
            ->ofType(JournalEntryType::Closing)
            ->withReference("CIERRE-{$year->name}");

        if ($netProfit->isPositive()) {
            // Utilidad: el resumen queda saldado y el patrimonio aumenta.
            $draft->debit($summaryAccountId, $netProfit, 'Cancelación del resumen')
                ->credit($retainedAccountId, $netProfit, 'Utilidad del ejercicio');
        } else {
            $loss = $netProfit->negated();
            $draft->debit($retainedAccountId, $loss, 'Pérdida del ejercicio')
                ->credit($summaryAccountId, $loss, 'Cancelación del resumen');
        }

        return $this->engine->post($draft);
    }

    private function closeRemainingPeriods(FiscalYear $year, ?int $userId): void
    {
        $year->periods()
            ->where('status', PeriodStatus::Open)
            ->orderBy('number')
            ->get()
            ->each(fn (AccountingPeriod $period) => $this->periods->close($period, $userId));
    }
}
