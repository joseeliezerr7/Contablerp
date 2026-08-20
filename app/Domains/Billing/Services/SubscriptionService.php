<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Enums\SubscriptionStatus;
use App\Domains\Billing\Exceptions\BillingException;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Tenancy\Enums\TenantStatus;
use App\Domains\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de la suscripción.
 *
 * ## Esto no es contabilidad
 *
 * Ninguno de estos métodos llama al motor contable. Lo que el proveedor le cobra
 * al cliente por usar el sistema es ingreso del proveedor, y el proveedor no
 * lleva su contabilidad aquí. Meterlo en el libro del cliente le añadiría un
 * gasto que él nunca capturó, y encima cambiaría su utilidad.
 *
 * Hay una prueba de invariante que lo comprueba: después de suscribir, facturar,
 * cobrar y cancelar, el libro del tenant sigue exactamente igual.
 *
 * ## Estado del tenant y estado de la suscripción
 *
 * El tenant ya tenía su propio estado desde el inicio. Se mantienen sincronizados
 * en un solo sitio —aquí— porque el acceso al sistema lo decide el tenant y el
 * cobro lo decide la suscripción, y tener dos verdades sobre si alguien puede
 * entrar es pedir que se contradigan.
 */
final class SubscriptionService
{
    public function __construct(private readonly QuotaService $quotas) {}

    /**
     * Suscribe un tenant a un plan, arrancando el período de prueba.
     */
    public function subscribe(Tenant $tenant, Plan $plan, DateTimeInterface|string|null $startingOn = null): Subscription
    {
        if ($this->quotas->subscriptionFor($tenant) !== null) {
            throw BillingException::alreadySubscribed();
        }

        if (! $plan->is_active) {
            throw BillingException::planUnavailable();
        }

        return DB::transaction(function () use ($tenant, $plan, $startingOn): Subscription {
            $start = CarbonImmutable::parse($startingOn ?? now())->startOfDay();
            $trialEnds = $plan->trial_days > 0 ? $start->addDays($plan->trial_days) : null;

            $subscription = new Subscription;
            $subscription->forceFill([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => $trialEnds === null ? SubscriptionStatus::Active : SubscriptionStatus::Trialing,
                // Copiados del plan: la suscripción es el contrato.
                'price' => $plan->price,
                'currency_code' => $plan->currency_code,
                'interval' => $plan->interval,
                'max_companies' => $plan->max_companies,
                'max_users' => $plan->max_users,
                'max_branches' => $plan->max_branches,
                'trial_ends_at' => $trialEnds,
                'current_period_start' => $start->toDateString(),
                'current_period_end' => $this->periodEnd($start, $plan->interval)->toDateString(),
            ])->save();

            $tenant->forceFill([
                'status' => $trialEnds === null ? TenantStatus::Active : TenantStatus::Trial,
                'trial_ends_at' => $trialEnds,
            ])->save();

            return $subscription->refresh();
        });
    }

    /**
     * Activa la suscripción: fin de la prueba o reactivación tras suspender.
     */
    public function activate(Subscription $subscription): Subscription
    {
        $this->guardLive($subscription);

        return DB::transaction(function () use ($subscription): Subscription {
            $subscription->forceFill([
                'status' => SubscriptionStatus::Active,
                'trial_ends_at' => null,
                'suspended_at' => null,
            ])->save();

            $subscription->tenant->forceFill([
                'status' => TenantStatus::Active,
                'trial_ends_at' => null,
            ])->save();

            return $subscription->refresh();
        });
    }

    /**
     * Cierra el período y emite la factura del servicio.
     *
     * La suscripción pasa a «con pago pendiente» hasta que se registre el cobro:
     * el cliente **sigue trabajando**. Cortarle el acceso el día que vence una
     * factura es la forma más rápida de perderlo; suspender es una decisión
     * comercial aparte.
     */
    public function renew(Subscription $subscription, DateTimeInterface|string|null $on = null): SubscriptionInvoice
    {
        $this->guardLive($subscription);

        return DB::transaction(function () use ($subscription, $on): SubscriptionInvoice {
            $issuedOn = CarbonImmutable::parse($on ?? now())->startOfDay();

            $periodStart = CarbonImmutable::parse($subscription->current_period_start);
            $periodEnd = CarbonImmutable::parse($subscription->current_period_end);

            $invoice = $this->issueInvoice($subscription, $issuedOn, $periodStart, $periodEnd);

            $nextStart = $periodEnd->addDay();

            $subscription->forceFill([
                'status' => $subscription->priceAmount()->isPositive()
                    ? SubscriptionStatus::PastDue
                    : SubscriptionStatus::Active,
                'trial_ends_at' => null,
                'current_period_start' => $nextStart->toDateString(),
                'current_period_end' => $this->periodEnd($nextStart, $subscription->interval)->toDateString(),
            ])->save();

            $subscription->tenant->forceFill([
                'status' => TenantStatus::Active,
                'trial_ends_at' => null,
            ])->save();

            return $invoice;
        });
    }

    /**
     * Registra el cobro de una factura del servicio.
     */
    public function recordPayment(SubscriptionInvoice $invoice, ?string $reference = null, DateTimeInterface|string|null $on = null): SubscriptionInvoice
    {
        return DB::transaction(function () use ($invoice, $reference, $on): SubscriptionInvoice {
            $invoice->forceFill([
                'status' => InvoiceStatus::Paid,
                'paid_on' => CarbonImmutable::parse($on ?? now())->toDateString(),
                'payment_reference' => $reference,
            ])->save();

            $subscription = $invoice->subscription;

            // Si ya no queda nada pendiente, vuelve a estar al día.
            $stillOwing = $subscription->invoices()->pending()->exists();

            if (! $stillOwing && $subscription->status === SubscriptionStatus::PastDue) {
                $subscription->forceFill(['status' => SubscriptionStatus::Active])->save();
            }

            return $invoice->refresh();
        });
    }

    /**
     * Suspende el acceso. Decisión comercial, no automática.
     */
    public function suspend(Subscription $subscription, string $reason): Subscription
    {
        $this->guardLive($subscription);

        return DB::transaction(function () use ($subscription, $reason): Subscription {
            $subscription->forceFill([
                'status' => SubscriptionStatus::Suspended,
                'suspended_at' => now(),
                'notes' => $reason,
            ])->save();

            $subscription->tenant->forceFill(['status' => TenantStatus::Suspended])->save();

            return $subscription->refresh();
        });
    }

    public function cancel(Subscription $subscription, string $reason): Subscription
    {
        if ($subscription->isCancelled()) {
            throw BillingException::alreadyCancelled($subscription);
        }

        return DB::transaction(function () use ($subscription, $reason): Subscription {
            $subscription->forceFill([
                'status' => SubscriptionStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $subscription->tenant->forceFill(['status' => TenantStatus::Cancelled])->save();

            return $subscription->refresh();
        });
    }

    /**
     * Cambia de plan conservando el período en curso.
     *
     * No prorratea: el precio nuevo entra en el período siguiente. Prorratear a
     * mitad de mes es una fuente de discusiones con el cliente que no compensa
     * en una tarifa mensual pequeña.
     */
    public function changePlan(Subscription $subscription, Plan $plan): Subscription
    {
        $this->guardLive($subscription);

        if (! $plan->is_active) {
            throw BillingException::planUnavailable();
        }

        return DB::transaction(function () use ($subscription, $plan): Subscription {
            $subscription->forceFill([
                'plan_id' => $plan->id,
                'price' => $plan->price,
                'currency_code' => $plan->currency_code,
                'interval' => $plan->interval,
                'max_companies' => $plan->max_companies,
                'max_users' => $plan->max_users,
                'max_branches' => $plan->max_branches,
            ])->save();

            return $subscription->refresh();
        });
    }

    /**
     * Suscripciones cuya prueba ya venció.
     *
     * Es lo que consultaría una tarea programada para avisar al cliente; no se
     * les corta el acceso solo, porque perder una cuenta por un descuido de
     * cobro es peor que un día de servicio regalado.
     *
     * @return Collection<int, Subscription>
     */
    public function expiredTrials(DateTimeInterface|string|null $asOf = null): Collection
    {
        return Subscription::query()
            ->with('tenant')
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', CarbonImmutable::parse($asOf ?? now()))
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Interno
    |--------------------------------------------------------------------------
    */

    private function issueInvoice(
        Subscription $subscription,
        CarbonImmutable $issuedOn,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): SubscriptionInvoice {
        $invoice = new SubscriptionInvoice;
        $invoice->forceFill([
            'subscription_id' => $subscription->id,
            'tenant_id' => $subscription->tenant_id,
            'number' => $this->nextInvoiceNumber(),
            'issued_on' => $issuedOn->toDateString(),
            'due_on' => $issuedOn->addDays(15)->toDateString(),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'amount' => $subscription->price,
            'currency_code' => $subscription->currency_code,
            'status' => $subscription->priceAmount()->isPositive()
                ? InvoiceStatus::Pending
                : InvoiceStatus::Paid,
            'paid_on' => $subscription->priceAmount()->isPositive() ? null : $issuedOn->toDateString(),
        ])->save();

        return $invoice->refresh();
    }

    /**
     * Correlativo global de facturas del servicio.
     *
     * No usa `DocumentSeriesService` a propósito: aquel numera por empresa
     * cliente, y estas facturas son del proveedor, que es uno solo.
     */
    private function nextInvoiceNumber(): string
    {
        $last = SubscriptionInvoice::query()
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('number');

        $next = $last === null ? 1 : ((int) substr($last, 4)) + 1;

        return 'SUS-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function periodEnd(CarbonImmutable $start, string $interval): CarbonImmutable
    {
        return $interval === 'yearly'
            ? $start->addYear()->subDay()
            : $start->addMonth()->subDay();
    }

    private function guardLive(Subscription $subscription): void
    {
        if ($subscription->isCancelled()) {
            throw BillingException::notLive($subscription);
        }
    }
}
