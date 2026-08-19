<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntryLine;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Consultas de solo lectura sobre el libro.
 *
 * Solo considera partidas contabilizadas: los borradores y las anuladas no
 * forman parte del mayor.
 */
final class LedgerQueryService
{
    /**
     * Movimientos de una cuenta en un rango, con saldo acumulado.
     *
     * El saldo se acumula respetando la naturaleza de la cuenta: en una cuenta
     * deudora el cargo suma y el abono resta; en una acreedora, al revés. Usar
     * siempre debe menos haber mostraría los pasivos en negativo.
     *
     * @return array{opening: Money, rows: Collection<int, array<string, mixed>>, debit: Money, credit: Money, closing: Money}
     */
    public function ledgerFor(
        Account $account,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        ?int $branchId = null,
    ): array {
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->endOfDay();

        $opening = $this->balanceBefore($account, $from, $branchId);

        // Las columnas van cualificadas: el JOIN con journal_entries trae sus
        // propias `branch_id`, `company_id` y `id`.
        $lines = JournalEntryLine::query()
            ->where('journal_entry_lines.account_id', $account->id)
            ->when($branchId !== null, fn ($query) => $query->where('journal_entry_lines.branch_id', $branchId))
            ->whereHas('entry', fn ($query) => $query
                ->where('status', JournalEntryStatus::Posted)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()]))
            ->with(['entry:id,number,date,concept,reference,type', 'branch:id,code,name'])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->orderBy('journal_entries.date')
            ->orderBy('journal_entries.number')
            ->orderBy('journal_entry_lines.line_number')
            ->select('journal_entry_lines.*')
            ->get();

        $running = $opening;
        $totalDebit = Money::zero();
        $totalCredit = Money::zero();

        $rows = $lines->map(function (JournalEntryLine $line) use (&$running, &$totalDebit, &$totalCredit, $account): array {
            $debit = $line->debitAmount();
            $credit = $line->creditAmount();

            $totalDebit = $totalDebit->plus($debit);
            $totalCredit = $totalCredit->plus($credit);
            $running = $running->plus($account->nature->balanceOf($debit, $credit));

            return [
                'date' => $line->entry->date,
                'number' => $line->entry->number,
                'concept' => $line->description ?: $line->entry->concept,
                'reference' => $line->entry->reference,
                'branch' => $line->branch?->name,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $running,
                'entry_id' => $line->journal_entry_id,
            ];
        });

        return [
            'opening' => $opening,
            'rows' => $rows,
            'debit' => $totalDebit,
            'credit' => $totalCredit,
            'closing' => $running,
        ];
    }

    /**
     * Saldo de la cuenta justo antes de la fecha indicada, con signo según su
     * naturaleza.
     */
    public function balanceBefore(Account $account, DateTimeInterface|string $date, ?int $branchId = null): Money
    {
        $date = CarbonImmutable::parse($date)->startOfDay();

        $totals = JournalEntryLine::query()
            ->where('journal_entry_lines.account_id', $account->id)
            ->when($branchId !== null, fn ($query) => $query->where('journal_entry_lines.branch_id', $branchId))
            ->whereHas('entry', fn ($query) => $query
                ->where('status', JournalEntryStatus::Posted)
                ->where('date', '<', $date->toDateString()))
            ->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->first();

        return $account->nature->balanceOf(
            Money::of((string) ($totals->total_debit ?? '0')),
            Money::of((string) ($totals->total_credit ?? '0')),
        );
    }

    /**
     * Cuentas imputables que tuvieron movimiento en el rango. Sirve para no
     * ofrecer en el selector del mayor cuentas que no mostrarían nada.
     *
     * @return Collection<int, Account>
     */
    public function accountsWithActivity(DateTimeInterface|string $from, DateTimeInterface|string $to): Collection
    {
        $from = CarbonImmutable::parse($from)->toDateString();
        $to = CarbonImmutable::parse($to)->toDateString();

        return Account::query()
            ->whereHas('lines.entry', fn ($query) => $query
                ->where('status', JournalEntryStatus::Posted)
                ->whereBetween('date', [$from, $to]))
            ->orderBy('code')
            ->get();
    }
}
