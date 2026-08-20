<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Services\FinancialStatementService;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Fiscal\Services\FiscalAuthorizationService;
use App\Domains\Payables\Models\Payable;
use App\Domains\Receivables\Models\Receivable;
use App\Domains\Receivables\Services\ReceivableService;
use App\Domains\Sales\Models\Sale;
use App\Domains\Treasury\Enums\CashSessionStatus;
use App\Domains\Treasury\Models\CashSession;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Lo primero que ve alguien al entrar.
 *
 * ## Cada panel se gana su lugar por permiso, no por rol
 *
 * Un cajero entra a este mismo dashboard y no tiene por qué ver la utilidad del
 * mes ni los saldos bancarios. Por eso **ningún dato se calcula antes de
 * comprobar el permiso**: no se trata de esconder una tarjeta con CSS, se trata
 * de no ejecutar la consulta. Si el permiso falta, el arreglo llega vacío y la
 * vista no dibuja el panel.
 *
 * Es el mismo error que ya apareció varias veces en este proyecto: un rol que
 * podía crear algo que no podía ver. Aquí se paga por adelantado.
 */
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render(
        FinancialStatementService $statements,
        ReceivableService $receivables,
        LedgerQueryService $ledger,
        FiscalAuthorizationService $authorizations,
    ): View {
        $company = app(CompanyContext::class)->companyOrFail();
        $today = CarbonImmutable::now()->startOfDay();

        return view('livewire.dashboard', [
            'company' => $company,
            'today' => $today,
            'sales' => $this->sales($today),
            'salesByMonth' => $this->salesByMonth($today),
            'receivables' => $this->receivables($today),
            'payables' => $this->payables($today),
            'aging' => $this->aging($receivables, $today),
            'profit' => $this->profit($statements, $today),
            'cashAccounts' => $this->cashAccounts($ledger, $today),
            'alerts' => $this->alerts($authorizations, $today),
            'latestInvoices' => $this->latestInvoices(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Ventas
    |--------------------------------------------------------------------------
    */

    /**
     * Ventas del mes contra el mes anterior completo.
     *
     * @return array{total: Money, previous: Money, change: int|null, count: int}|null
     */
    private function sales(CarbonImmutable $today): ?array
    {
        if (! auth()->user()?->can('sales.invoices.view')) {
            return null;
        }

        $current = $this->salesTotalBetween($today->startOfMonth(), $today->endOfMonth());
        $previous = $this->salesTotalBetween(
            $today->subMonth()->startOfMonth(),
            $today->subMonth()->endOfMonth(),
        );

        return [
            'total' => $current,
            'previous' => $previous,
            'change' => $this->percentChange($previous, $current),
            'count' => Sale::query()
                ->issued()
                ->whereBetween('date', [
                    $today->startOfMonth()->toDateString(),
                    $today->endOfMonth()->toDateString(),
                ])
                ->count(),
        ];
    }

    /**
     * Serie de doce meses para la gráfica de columnas.
     *
     * Se arma con **una sola consulta agrupada** y luego se rellenan los meses
     * sin ventas: un mes en cero es información —el negocio paró— y saltárselo
     * deformaría la gráfica.
     *
     * @return list<array{label: string, month: string, total: Money}>
     */
    private function salesByMonth(CarbonImmutable $today): array
    {
        if (! auth()->user()?->can('sales.invoices.view')) {
            return [];
        }

        $from = $today->startOfMonth()->subMonths(11);

        $totals = Sale::query()
            ->issued()
            ->whereBetween('date', [$from->toDateString(), $today->endOfMonth()->toDateString()])
            ->selectRaw("DATE_FORMAT(`date`, '%Y-%m') as ym, SUM(total) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $series = [];

        for ($i = 0; $i < 12; $i++) {
            $month = $from->addMonths($i);
            $key = $month->format('Y-m');

            $series[] = [
                'label' => mb_convert_case($month->translatedFormat('M'), MB_CASE_TITLE),
                'month' => $month->translatedFormat('F Y'),
                'total' => Money::of((string) ($totals[$key] ?? '0')),
            ];
        }

        return $series;
    }

    private function salesTotalBetween(CarbonImmutable $from, CarbonImmutable $to): Money
    {
        return Money::of((string) (Sale::query()
            ->issued()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('total') ?: '0'));
    }

    /**
     * @return list<array{number: string|null, customer: string, date: Carbon, total: Money}>
     */
    private function latestInvoices(): array
    {
        if (! auth()->user()?->can('sales.invoices.view')) {
            return [];
        }

        return Sale::query()
            ->issued()
            ->with('customer:id,name,trade_name')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn (Sale $sale) => [
                // El folio fiscal de una venta vive en `number`; `document_number`
                // es de cuentas por cobrar y pagar, y pedirlo aquí reventaría con
                // `preventAccessingMissingAttributes`.
                'number' => $sale->number,
                'customer' => $sale->customer?->trade_name ?: ($sale->customer?->name ?? 'Consumidor final'),
                'date' => $sale->date,
                'total' => $sale->totalAmount(),
            ])
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Cobrar y pagar
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{total: Money, overdue: Money, overdueCount: int}|null
     */
    private function receivables(CarbonImmutable $today): ?array
    {
        if (! auth()->user()?->can('receivables.view')) {
            return null;
        }

        $overdue = Receivable::query()->overdue($today)->get();

        return [
            'total' => Money::of((string) (Receivable::query()->outstanding()->sum('balance') ?: '0')),
            'overdue' => Money::sum($overdue->map->balanceAmount()->all()),
            'overdueCount' => $overdue->count(),
        ];
    }

    /**
     * @return array{total: Money, overdue: Money, overdueCount: int}|null
     */
    private function payables(CarbonImmutable $today): ?array
    {
        if (! auth()->user()?->can('payables.view')) {
            return null;
        }

        $overdue = Payable::query()->overdue($today)->get();

        return [
            'total' => Money::of((string) (Payable::query()->outstanding()->sum('balance') ?: '0')),
            'overdue' => Money::sum($overdue->map->balanceAmount()->all()),
            'overdueCount' => $overdue->count(),
        ];
    }

    /**
     * Antigüedad por tramos para la barra apilada.
     *
     * @return list<array{key: string, label: string, amount: Money}>
     */
    private function aging(ReceivableService $receivables, CarbonImmutable $today): array
    {
        if (! auth()->user()?->can('receivables.reports')) {
            return [];
        }

        $totals = $receivables->aging($today)['totals'];

        $buckets = [
            'current' => 'Corriente',
            'd30' => '1–30 días',
            'd60' => '31–60 días',
            'd90' => '61–90 días',
            'over' => 'Más de 90',
        ];

        $rows = [];

        foreach ($buckets as $key => $label) {
            $rows[] = [
                'key' => $key,
                'label' => $label,
                'amount' => $totals[$key] ?? Money::zero(),
            ];
        }

        return $rows;
    }

    /*
    |--------------------------------------------------------------------------
    | Resultado y efectivo
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{net: Money, income: Money, expense: Money}|null
     */
    private function profit(FinancialStatementService $statements, CarbonImmutable $today): ?array
    {
        if (! auth()->user()?->can('accounting.reports.view')) {
            return null;
        }

        $statement = $statements->incomeStatement($today->startOfMonth(), $today->endOfMonth());

        return [
            'net' => $statement['net_profit'],
            'income' => $statement['total_income'],
            'expense' => $statement['total_cost']->plus($statement['total_expense']),
        ];
    }

    /**
     * Saldos de caja y bancos, de las cuentas marcadas como equivalentes de
     * efectivo en el plan de cuentas.
     *
     * @return list<array{name: string, code: string, balance: Money}>
     */
    private function cashAccounts(LedgerQueryService $ledger, CarbonImmutable $today): array
    {
        if (! auth()->user()?->can('treasury.banks.view')) {
            return [];
        }

        return Account::query()
            ->cash()
            ->postable()
            ->orderBy('code')
            ->get()
            ->map(fn (Account $account) => [
                'name' => $account->name,
                'code' => $account->code,
                // `balanceBefore` corta *antes* de la fecha, así que se le pide
                // mañana para incluir todo lo de hoy.
                'balance' => $ledger->balanceBefore($account, $today->addDay()),
            ])
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Lo que hay que atender
    |--------------------------------------------------------------------------
    */

    /**
     * Avisos accionables, cada uno con su enlace.
     *
     * Solo aparecen los que el usuario puede resolver: avisarle a un vendedor
     * que el CAI se está agotando no sirve de nada, porque no puede registrar
     * uno nuevo.
     *
     * @return list<array{level: string, title: string, detail: string, route: string|null}>
     */
    private function alerts(FiscalAuthorizationService $authorizations, CarbonImmutable $today): array
    {
        $user = auth()->user();
        $alerts = [];

        if ($user?->can('fiscal.authorizations.view')) {
            foreach ($authorizations->needingRenewal() as $authorization) {
                $days = $today->diffInDays(CarbonImmutable::parse($authorization->limit_date), absolute: false);

                $alerts[] = [
                    'level' => $days <= 15 || $authorization->usedPercent() >= 95 ? 'critical' : 'warning',
                    'title' => 'CAI por renovar',
                    'detail' => sprintf(
                        '%s — %d%% usado, quedan %s correlativos y vence el %s.',
                        $authorization->document_type->label(),
                        $authorization->usedPercent(),
                        number_format($authorization->remaining()),
                        CarbonImmutable::parse($authorization->limit_date)->format('d/m/Y'),
                    ),
                    'route' => route('fiscal.points.index'),
                ];
            }
        }

        if ($user?->can('receivables.view')) {
            $overdue = Receivable::query()->overdue($today)->count();

            if ($overdue > 0) {
                $alerts[] = [
                    'level' => 'warning',
                    'title' => 'Facturas vencidas por cobrar',
                    'detail' => $overdue === 1
                        ? 'Hay 1 factura vencida sin cobrar.'
                        : "Hay {$overdue} facturas vencidas sin cobrar.",
                    'route' => route('receivables.aging'),
                ];
            }
        }

        if ($user?->can('treasury.cash.view')) {
            $open = CashSession::query()->where('status', CashSessionStatus::Open)->count();

            if ($open > 0) {
                $alerts[] = [
                    'level' => 'info',
                    'title' => 'Caja abierta',
                    'detail' => $open === 1
                        ? 'Hay 1 caja sin cerrar.'
                        : "Hay {$open} cajas sin cerrar.",
                    'route' => route('treasury.cash.index'),
                ];
            }
        }

        return $alerts;
    }

    /*
    |--------------------------------------------------------------------------
    | Utilidades
    |--------------------------------------------------------------------------
    */

    /**
     * Variación porcentual, o null cuando no hay base con la que comparar.
     *
     * Sin la guarda del cero, un mes anterior en cero daría una división por
     * cero; y «creció ∞ %» no es un dato que sirva a nadie.
     */
    private function percentChange(Money $previous, Money $current): ?int
    {
        if ($previous->isZero()) {
            return null;
        }

        $delta = $current->minus($previous);

        return (int) round((float) $delta->toString() / (float) $previous->toString() * 100);
    }

    /**
     * El mayor de la serie, para escalar la gráfica.
     *
     * @param  Collection<int, Money>|list<Money>  $amounts
     */
    public static function peak(iterable $amounts): Money
    {
        $peak = Money::zero();

        foreach ($amounts as $amount) {
            if ($amount->greaterThan($peak)) {
                $peak = $amount;
            }
        }

        return $peak;
    }
}
