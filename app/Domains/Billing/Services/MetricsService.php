<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Enums\SubscriptionStatus;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Métricas del negocio, para el panel del proveedor.
 *
 * Todas cruzan tenants a propósito. Es la única parte del sistema que lo hace, y
 * por eso vive detrás del middleware de superadministrador: en cualquier otro
 * sitio, una consulta que cruza cuentas es un fallo de aislamiento.
 */
final class MetricsService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(DateTimeInterface|string|null $asOf = null): array
    {
        $now = CarbonImmutable::parse($asOf ?? now());

        return [
            'tenants' => Tenant::query()->count(),
            'trialing' => Subscription::query()->where('status', SubscriptionStatus::Trialing)->count(),
            'active' => Subscription::query()->where('status', SubscriptionStatus::Active)->count(),
            'past_due' => Subscription::query()->where('status', SubscriptionStatus::PastDue)->count(),
            'suspended' => Subscription::query()->where('status', SubscriptionStatus::Suspended)->count(),
            'cancelled' => Subscription::query()->where('status', SubscriptionStatus::Cancelled)->count(),
            'mrr' => $this->monthlyRecurringRevenue(),
            'outstanding' => $this->outstandingInvoices(),
            'expiring_trials' => $this->trialsExpiringWithin(7, $now),
        ];
    }

    /**
     * Ingreso recurrente mensual: lo que entra cada mes de las cuentas que
     * pagan.
     *
     * Los planes anuales se mensualizan; si no, un cliente anual inflaría el
     * mes en que firmó y dejaría los once siguientes a cero.
     */
    public function monthlyRecurringRevenue(): Money
    {
        return Money::sum(
            Subscription::query()->billable()->get()
                ->map(fn (Subscription $s) => $s->monthlyPrice())
                ->all()
        );
    }

    /**
     * Facturas del servicio emitidas y no cobradas.
     */
    public function outstandingInvoices(): Money
    {
        return Money::of((string) SubscriptionInvoice::query()->pending()->sum('amount'));
    }

    /**
     * Cuántas pruebas vencen en los próximos días. Es la lista de llamadas
     * pendientes del equipo comercial.
     */
    public function trialsExpiringWithin(int $days, DateTimeInterface|string|null $asOf = null): int
    {
        $now = CarbonImmutable::parse($asOf ?? now());

        return Subscription::query()
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [$now, $now->addDays($days)])
            ->count();
    }

    /**
     * Reparto de cuentas por plan, para ver qué se vende.
     *
     * @return array<int, array{plan: string, count: int, mrr: Money}>
     */
    public function byPlan(): array
    {
        return Subscription::query()
            ->with('plan:id,name')
            ->live()
            ->get()
            ->groupBy(fn (Subscription $s) => $s->plan->name)
            ->map(fn ($group, string $plan) => [
                'plan' => $plan,
                'count' => $group->count(),
                'mrr' => Money::sum(
                    $group->filter(fn (Subscription $s) => $s->status->isBillable())
                        ->map(fn (Subscription $s) => $s->monthlyPrice())
                        ->all()
                ),
            ])
            ->values()
            ->all();
    }
}
