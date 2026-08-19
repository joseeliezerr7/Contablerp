<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransfer\StatementRow;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Enums\JournalEntryType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\FiscalYear;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Balance de comprobación, estado de resultados y balance general.
 *
 * Todos se derivan del mismo agregado sobre `journal_entry_lines`, para que no
 * puedan contradecirse entre sí: si el balance de comprobación cuadra, los
 * otros dos también.
 *
 * Tratamiento de las partidas de cierre — importa entenderlo:
 *
 *  · El **estado de resultados** las excluye. La partida de cierre cancela los
 *    ingresos y gastos del año contra el resumen de resultados; si se
 *    incluyera, el estado de resultados de un ejercicio ya cerrado saldría en
 *    cero.
 *
 *  · El **balance general** las incluye. Así, la utilidad del ejercicio (el
 *    saldo acumulado de las cuentas de resultado) vale la utilidad real
 *    mientras el año está abierto, y cero una vez cerrado, cuando esa utilidad
 *    ya vive en Utilidades Retenidas. El balance cuadra en ambos momentos.
 */
final class FinancialStatementService
{
    public function __construct(private readonly CompanyContext $context) {}

    /**
     * Balance de comprobación: saldo inicial, movimiento del período y saldo
     * final de cada cuenta imputable.
     *
     * @return array{rows: Collection<int, StatementRow>, opening_debit: Money, opening_credit: Money, debit: Money, credit: Money, closing_debit: Money, closing_credit: Money, balanced: bool}
     */
    public function trialBalance(
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        ?int $branchId = null,
        bool $onlyWithActivity = true,
    ): array {
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->endOfDay();

        $rows = $this->rowsFor($from, $to, $branchId, includeClosing: true)
            ->when($onlyWithActivity, fn (Collection $rows) => $rows->filter->hasActivity())
            ->values();

        $debit = Money::sum($rows->map(fn (StatementRow $r) => $r->debit)->all());
        $credit = Money::sum($rows->map(fn (StatementRow $r) => $r->credit)->all());
        $closingDebit = Money::sum($rows->map(fn (StatementRow $r) => $r->debitBalance())->all());
        $closingCredit = Money::sum($rows->map(fn (StatementRow $r) => $r->creditBalance())->all());

        return [
            'rows' => $rows,
            'opening_debit' => Money::sum($rows->map(
                fn (StatementRow $r) => $r->nature->value === 'debit' ? $r->opening : Money::zero()
            )->all()),
            'opening_credit' => Money::sum($rows->map(
                fn (StatementRow $r) => $r->nature->value === 'credit' ? $r->opening : Money::zero()
            )->all()),
            'debit' => $debit,
            'credit' => $credit,
            'closing_debit' => $closingDebit,
            'closing_credit' => $closingCredit,
            // Si esto es falso hay un error en el motor, no en el reporte.
            'balanced' => $debit->equals($credit) && $closingDebit->equals($closingCredit),
        ];
    }

    /**
     * Estado de resultados del período.
     *
     * @return array{income: Collection<int, StatementRow>, cost: Collection<int, StatementRow>, expense: Collection<int, StatementRow>, total_income: Money, total_cost: Money, gross_profit: Money, total_expense: Money, net_profit: Money}
     */
    public function incomeStatement(
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        ?int $branchId = null,
    ): array {
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->endOfDay();

        // Sin saldo inicial: el estado de resultados mide el movimiento del
        // período, no un acumulado histórico.
        $rows = $this->rowsFor($from, $to, $branchId, includeClosing: false, withOpening: false)
            ->filter(fn (StatementRow $row) => $row->type->isIncomeStatement())
            ->filter->hasActivity()
            ->values();

        $income = $rows->filter(fn (StatementRow $r) => $r->type === AccountType::Income)->values();
        $cost = $rows->filter(fn (StatementRow $r) => $r->type === AccountType::Cost)->values();
        $expense = $rows->filter(fn (StatementRow $r) => $r->type === AccountType::Expense)->values();

        // Con el saldo del tipo: «Descuentos sobre Ventas» es una cuenta de
        // ingreso con naturaleza deudora, y tiene que restar del ingreso, no
        // sumarse a él.
        $totalIncome = $this->sumStatement($income);
        $totalCost = $this->sumStatement($cost);
        $totalExpense = $this->sumStatement($expense);

        $grossProfit = $totalIncome->minus($totalCost);

        return [
            'income' => $income,
            'cost' => $cost,
            'expense' => $expense,
            'total_income' => $totalIncome,
            'total_cost' => $totalCost,
            'gross_profit' => $grossProfit,
            'total_expense' => $totalExpense,
            'net_profit' => $grossProfit->minus($totalExpense),
        ];
    }

    /**
     * Balance general a una fecha.
     *
     * @return array{assets: array<int, array<string, mixed>>, liabilities: array<int, array<string, mixed>>, equity: array<int, array<string, mixed>>, total_assets: Money, total_liabilities: Money, total_equity: Money, profit: Money, total_liabilities_and_equity: Money, difference: Money, balanced: bool}
     */
    public function balanceSheet(DateTimeInterface|string $asOf, ?int $branchId = null): array
    {
        $asOf = CarbonImmutable::parse($asOf)->endOfDay();

        // Acumulado desde el origen: las cuentas de balance arrastran saldo.
        $rows = $this->rowsFor(null, $asOf, $branchId, includeClosing: true, withOpening: false);

        $assets = $this->groupByParent($rows->filter(fn (StatementRow $r) => $r->type === AccountType::Asset));
        $liabilities = $this->groupByParent($rows->filter(fn (StatementRow $r) => $r->type === AccountType::Liability));
        $equity = $this->groupByParent($rows->filter(fn (StatementRow $r) => $r->type === AccountType::Equity));

        $totalAssets = $this->sumGroups($assets);
        $totalLiabilities = $this->sumGroups($liabilities);
        $totalEquity = $this->sumGroups($equity);

        // Utilidad del ejercicio aún no cerrada: ingresos menos costos menos
        // gastos. Sumar los tres bloques sin signo daría un patrimonio inflado.
        //
        // Incluye las partidas de cierre, así que vale cero cuando el ejercicio
        // ya se cerró y su resultado pasó a Utilidades Retenidas.
        $profit = $this->sumStatement($rows->filter(fn (StatementRow $r) => $r->type === AccountType::Income))
            ->minus($this->sumStatement($rows->filter(fn (StatementRow $r) => $r->type === AccountType::Cost)))
            ->minus($this->sumStatement($rows->filter(fn (StatementRow $r) => $r->type === AccountType::Expense)));

        $totalLiabilitiesAndEquity = $totalLiabilities->plus($totalEquity)->plus($profit);

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            'profit' => $profit,
            'total_liabilities_and_equity' => $totalLiabilitiesAndEquity,
            'difference' => $totalAssets->minus($totalLiabilitiesAndEquity),
            'balanced' => $totalAssets->equals($totalLiabilitiesAndEquity),
        ];
    }

    /**
     * Ejercicio fiscal que contiene la fecha, para encabezar los reportes.
     */
    public function fiscalYearFor(DateTimeInterface|string $date): ?FiscalYear
    {
        $date = CarbonImmutable::parse($date)->toDateString();

        return FiscalYear::query()
            ->where('starts_on', '<=', $date)
            ->where('ends_on', '>=', $date)
            ->first();
    }

    /**
     * Construye una línea por cada cuenta imputable.
     *
     * @return Collection<int, StatementRow>
     */
    private function rowsFor(
        ?CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $branchId,
        bool $includeClosing,
        bool $withOpening = true,
    ): Collection {
        $accounts = Account::query()->postable()->orderBy('code')->get();

        $movements = $this->aggregate($from, $to, $branchId, $includeClosing);
        $openings = $withOpening && $from !== null
            ? $this->aggregate(null, $from->subDay()->endOfDay(), $branchId, $includeClosing)
            : collect();

        return $accounts->map(function (Account $account) use ($movements, $openings): StatementRow {
            $movement = $movements->get($account->id);
            $opening = $openings->get($account->id);

            return StatementRow::make(
                $account,
                $opening === null
                    ? Money::zero()
                    : $account->nature->balanceOf(
                        Money::of((string) $opening->debit),
                        Money::of((string) $opening->credit),
                    ),
                Money::of((string) ($movement->debit ?? '0')),
                Money::of((string) ($movement->credit ?? '0')),
            );
        });
    }

    /**
     * Suma de débitos y créditos por cuenta en un rango.
     *
     * @return Collection<int, object{debit: string, credit: string}>
     */
    private function aggregate(
        ?CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $branchId,
        bool $includeClosing,
    ): Collection {
        return DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->context->idOrFail())
            ->where('e.status', JournalEntryStatus::Posted->value)
            ->where('e.date', '<=', $to->toDateString())
            ->when($from !== null, fn ($q) => $q->where('e.date', '>=', $from->toDateString()))
            ->when($branchId !== null, fn ($q) => $q->where('l.branch_id', $branchId))
            ->when(! $includeClosing, fn ($q) => $q->where('e.type', '!=', JournalEntryType::Closing->value))
            ->groupBy('l.account_id')
            ->selectRaw('l.account_id, SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->get()
            ->keyBy('account_id');
    }

    /**
     * Agrupa las cuentas de detalle bajo su grupo de segundo nivel
     * ('1.1 ACTIVO CORRIENTE'), que es como se presenta un balance.
     *
     * @param  Collection<int, StatementRow>  $rows
     * @return array<int, array{code: string, name: string, total: Money, rows: array<int, StatementRow>}>
     */
    private function groupByParent(Collection $rows): array
    {
        $withActivity = $rows->filter(fn (StatementRow $row) => ! $row->closing->isZero());

        if ($withActivity->isEmpty()) {
            return [];
        }

        $groupCodes = $withActivity
            ->map(fn (StatementRow $row) => $this->groupCodeOf($row->code))
            ->unique();

        $groups = Account::query()
            ->whereIn('code', $groupCodes)
            ->get()
            ->keyBy('code');

        return $withActivity
            ->groupBy(fn (StatementRow $row) => $this->groupCodeOf($row->code))
            ->map(function (Collection $groupRows, string $code) use ($groups): array {
                return [
                    'code' => $code,
                    'name' => $groups->get($code)?->name ?? $code,
                    // Con el saldo del tipo, no el de la cuenta: las cuentas de
                    // contrapartida —depreciación acumulada— tienen que restar.
                    'total' => $this->sumStatement($groupRows),
                    'rows' => $groupRows->values()->all(),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * '1.1.03.01' pertenece al grupo '1.1'.
     */
    private function groupCodeOf(string $code): string
    {
        $segments = explode('.', $code);

        return count($segments) < 2 ? $code : $segments[0].'.'.$segments[1];
    }

    /**
     * @param  Collection<int, StatementRow>  $rows
     */
    private function sumClosing(Collection $rows): Money
    {
        return Money::sum($rows->map(fn (StatementRow $row) => $row->closing)->all());
    }

    /**
     * Suma para el balance general: cada cuenta pesa según la naturaleza de su
     * tipo, de modo que las de contrapartida descuentan en vez de sumar.
     *
     * @param  Collection<int, StatementRow>  $rows
     */
    private function sumStatement(Collection $rows): Money
    {
        return Money::sum($rows->map(fn (StatementRow $row) => $row->statementBalance())->all());
    }

    /**
     * @param  array<int, array{total: Money}>  $groups
     */
    private function sumGroups(array $groups): Money
    {
        return Money::sum(array_map(fn (array $group) => $group['total'], $groups));
    }
}
