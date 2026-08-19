<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Enums\CashFlowClass;
use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\Account;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Estado de flujo de efectivo por método directo.
 *
 * La clasificación se deduce del propio libro, no de una tabla aparte. Como
 * toda partida cuadra, en cualquier partida que toque caja se cumple:
 *
 *     Σ(líneas de efectivo, debe − haber) = − Σ(líneas que no son efectivo, debe − haber)
 *
 * Es decir, el movimiento de caja de una partida está exactamente explicado por
 * sus contrapartidas. Por eso a cada línea que no es de efectivo se le atribuye
 * `haber − debe` como su aporte al flujo, y la suma de todos los aportes da la
 * variación real de caja — sin estimaciones ni prorrateos.
 *
 * Cada aporte se clasifica en operación, inversión o financiamiento según la
 * clasificación de su cuenta; lo no clasificado se trata como operación, que es
 * la categoría residual en la NIC 7.
 */
final class CashFlowService
{
    public function __construct(private readonly CompanyContext $context) {}

    /**
     * @return array{sections: array<string, array{label: string, rows: array<int, array<string, mixed>>, total: Money}>, opening_cash: Money, closing_cash: Money, net_change: Money, computed_change: Money, reconciled: bool, cash_accounts: Collection<int, Account>}
     */
    public function cashFlow(
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        ?int $branchId = null,
    ): array {
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->endOfDay();

        $cashAccounts = Account::query()->cash()->orderBy('code')->get();

        $openingCash = $this->cashBalanceAt($from->subDay()->endOfDay(), $branchId);
        $closingCash = $this->cashBalanceAt($to, $branchId);

        $sections = $this->classifiedFlows($from, $to, $branchId);

        $computedChange = Money::sum(array_map(
            fn (array $section) => $section['total'],
            array_values($sections),
        ));

        $netChange = $closingCash->minus($openingCash);

        return [
            'sections' => $sections,
            'opening_cash' => $openingCash,
            'closing_cash' => $closingCash,
            'net_change' => $netChange,
            'computed_change' => $computedChange,
            // Si esto es falso, el reporte no cuadra con el saldo real de caja
            // y hay un error en el motor o en la clasificación.
            'reconciled' => $netChange->equals($computedChange),
            'cash_accounts' => $cashAccounts,
        ];
    }

    /**
     * Saldo agregado de todas las cuentas de efectivo a una fecha.
     */
    public function cashBalanceAt(DateTimeInterface|string $date, ?int $branchId = null): Money
    {
        $date = CarbonImmutable::parse($date)->endOfDay();

        $totals = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $this->context->idOrFail())
            ->where('e.status', JournalEntryStatus::Posted->value)
            ->where('a.is_cash_equivalent', true)
            ->where('e.date', '<=', $date->toDateString())
            ->when($branchId !== null, fn ($q) => $q->where('l.branch_id', $branchId))
            ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
            ->first();

        // Las cuentas de efectivo son deudoras: el cargo aumenta la caja.
        return Money::of((string) $totals->debit)->minus(Money::of((string) $totals->credit));
    }

    /**
     * @return array<string, array{label: string, rows: array<int, array<string, mixed>>, total: Money}>
     */
    private function classifiedFlows(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): array
    {
        $companyId = $this->context->idOrFail();

        $rows = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $companyId)
            ->where('e.status', JournalEntryStatus::Posted->value)
            ->whereBetween('e.date', [$from->toDateString(), $to->toDateString()])
            ->where('a.is_cash_equivalent', false)
            ->when($branchId !== null, fn ($q) => $q->where('l.branch_id', $branchId))
            // Solo las partidas que efectivamente movieron caja.
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('journal_entry_lines as cl')
                    ->join('accounts as ca', 'ca.id', '=', 'cl.account_id')
                    ->whereColumn('cl.journal_entry_id', 'e.id')
                    ->where('ca.is_cash_equivalent', true);
            })
            ->groupBy('l.account_id', 'a.code', 'a.name', 'a.cash_flow_class')
            ->selectRaw('l.account_id, a.code, a.name, a.cash_flow_class, SUM(l.credit) - SUM(l.debit) as amount')
            ->orderBy('a.code')
            ->get();

        $sections = [];

        foreach (CashFlowClass::cases() as $class) {
            $sections[$class->value] = ['label' => $class->label(), 'rows' => [], 'total' => Money::zero()];
        }

        foreach ($rows as $row) {
            $amount = Money::of((string) $row->amount);

            if ($amount->isZero()) {
                continue;
            }

            // Sin clasificar cae en operación: es la categoría residual.
            $class = $row->cash_flow_class !== null
                ? CashFlowClass::from($row->cash_flow_class)
                : CashFlowClass::Operating;

            $sections[$class->value]['rows'][] = [
                'code' => $row->code,
                'name' => $row->name,
                'amount' => $amount,
            ];

            $sections[$class->value]['total'] = $sections[$class->value]['total']->plus($amount);
        }

        return $sections;
    }
}
