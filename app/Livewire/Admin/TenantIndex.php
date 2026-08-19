<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domains\Billing\Enums\SubscriptionStatus;
use App\Domains\Billing\Exceptions\BillingException;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Billing\Services\MetricsService;
use App\Domains\Billing\Services\QuotaService;
use App\Domains\Billing\Services\SubscriptionService;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Panel del proveedor: todas las cuentas del servicio.
 *
 * Es la única pantalla del sistema que mira entre tenants, y por eso vive tras
 * el middleware de superadministrador y fuera del de empresa.
 */
#[Title('Cuentas del servicio')]
class TenantIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'estado', except: '')]
    public string $statusFilter = '';

    public ?int $actingOn = null;

    public string $action = '';

    public string $reason = '';

    public ?int $newPlanId = null;

    public string $paymentReference = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter'], strict: true)) {
            $this->resetPage();
        }
    }

    public function confirm(int $subscriptionId, string $action): void
    {
        $this->actingOn = $subscriptionId;
        $this->action = $action;
        $this->reason = '';
        $this->newPlanId = Subscription::query()->findOrFail($subscriptionId)->plan_id;
    }

    public function apply(SubscriptionService $subscriptions): void
    {
        // El cobro no pasa por aquí: cada factura tiene su propio botón. Si el
        // usuario pulsa Enter en la referencia, no debe ocurrir nada.
        if ($this->action === 'pay') {
            return;
        }

        $subscription = Subscription::query()->findOrFail($this->actingOn);

        if (in_array($this->action, ['suspend', 'cancel'], strict: true)) {
            $this->validate([
                'reason' => ['required', 'string', 'min:5', 'max:500'],
            ], attributes: ['reason' => 'motivo']);
        }

        try {
            match ($this->action) {
                'activate' => $subscriptions->activate($subscription),
                'suspend' => $subscriptions->suspend($subscription, $this->reason),
                'cancel' => $subscriptions->cancel($subscription, $this->reason),
                'renew' => $subscriptions->renew($subscription),
                'change_plan' => $subscriptions->changePlan(
                    $subscription,
                    Plan::query()->findOrFail($this->newPlanId),
                ),
                default => null,
            };
        } catch (BillingException $e) {
            // El diálogo se queda abierto con el motivo. Cerrarlo y mandar el
            // mensaje al fondo de la pantalla haría creer que algo pasó.
            $this->addError('action', $e->getMessage());

            return;
        }

        session()->flash('success', 'Suscripción actualizada.');
        $this->cancelAction();
    }

    /**
     * Registra el cobro de una factura del servicio.
     *
     * Va factura por factura y no «cobrar todo lo pendiente»: cada transferencia
     * que entra tiene su propia referencia bancaria, y esa referencia es lo que
     * permite después reconstruir qué se cobró y cuándo.
     */
    public function pay(int $invoiceId, SubscriptionService $subscriptions): void
    {
        $this->validate([
            'paymentReference' => ['nullable', 'string', 'max:100'],
        ], attributes: ['paymentReference' => 'referencia']);

        $invoice = SubscriptionInvoice::query()
            ->where('subscription_id', $this->actingOn)
            ->pending()
            ->findOrFail($invoiceId);

        $subscriptions->recordPayment($invoice, $this->paymentReference ?: null);

        session()->flash('success', 'Cobro registrado en la factura '.$invoice->number.'.');

        // Si ya no queda nada por cobrar, el diálogo no tiene más que enseñar.
        if ($this->pendingInvoices()->isEmpty()) {
            $this->cancelAction();
        }
    }

    /**
     * @return Collection<int, SubscriptionInvoice>
     */
    private function pendingInvoices(): Collection
    {
        if ($this->actingOn === null) {
            return collect();
        }

        return SubscriptionInvoice::query()
            ->where('subscription_id', $this->actingOn)
            ->pending()
            ->orderBy('issued_on')
            ->get();
    }

    public function cancelAction(): void
    {
        $this->reset(['actingOn', 'action', 'reason', 'newPlanId', 'paymentReference']);
        $this->resetValidation();
    }

    public function render(MetricsService $metrics, QuotaService $quotas): View
    {
        $subscriptions = Subscription::query()
            ->with(['tenant:id,name,slug,status', 'plan:id,name,price'])
            ->withCount(['invoices as pending_invoices_count' => fn ($q) => $q->pending()])
            ->when($this->search !== '', fn ($q) => $q->whereHas(
                'tenant',
                fn ($t) => $t->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('slug', 'like', '%'.$this->search.'%')
            ))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->orderByDesc('created_at')
            ->paginate(25);

        // El consumo se calcula por cuenta: es lo que dice si alguien se quedó
        // corto de plan.
        $subscriptions->getCollection()->transform(function (Subscription $s) use ($quotas): Subscription {
            $s->setAttribute('usage', $quotas->usage($s->tenant));

            return $s;
        });

        return view('livewire.admin.tenant-index', [
            'subscriptions' => $subscriptions,
            'summary' => $metrics->summary(),
            'byPlan' => $metrics->byPlan(),
            'statuses' => SubscriptionStatus::cases(),
            'plans' => Plan::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'pending' => $this->pendingInvoices(),
        ]);
    }
}
