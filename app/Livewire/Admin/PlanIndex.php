<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domains\Billing\Models\Plan;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Los planes que vendés.
 *
 * Era la última pieza del negocio propio sin pantalla: los planes solo se
 * *seleccionaban* —en el registro público y al cambiar la suscripción de un
 * tenant— y cambiar tu propio precio o crear un plan nuevo exigía entrar a
 * MySQL.
 *
 * ## Lo que esta pantalla no hace a propósito
 *
 * **Cambiar un plan no toca a nadie que ya lo tenga.** La suscripción copia sus
 * límites al contratarse (`max_users`, `max_companies`… viven también en
 * `subscriptions`), así que subir el precio o bajar un límite solo afecta a
 * quien contrate de ahí en adelante. Re-negociar a los existentes es una
 * decisión comercial que se hace tenant por tenant en «Cuentas del servicio»,
 * no un efecto colateral de editar un catálogo.
 *
 * Vive tras el middleware `superadmin`, fuera del contexto de empresa: los
 * planes son del proveedor, no de ningún cliente.
 */
#[Title('Planes del servicio')]
class PlanIndex extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $description = '';

    public string $price = '';

    public string $interval = 'monthly';

    public string $trial_days = '30';

    /** Vacíos = sin límite. */
    public string $max_companies = '';

    public string $max_users = '';

    public string $max_branches = '';

    public string $max_monthly_documents = '';

    public bool $has_inventory = true;

    public bool $has_treasury = true;

    public bool $has_fixed_assets = true;

    public bool $has_multi_company = false;

    public bool $is_public = true;

    public bool $is_active = true;

    public string $sort_order = '0';

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $plan = Plan::query()->findOrFail($id);

        $this->editingId = $plan->id;
        $this->code = $plan->code;
        $this->name = $plan->name;
        $this->description = (string) $plan->description;
        $this->price = Money::of($plan->price)->toScale(2);
        $this->interval = $plan->interval;
        $this->trial_days = (string) $plan->trial_days;
        $this->max_companies = (string) ($plan->max_companies ?? '');
        $this->max_users = (string) ($plan->max_users ?? '');
        $this->max_branches = (string) ($plan->max_branches ?? '');
        $this->max_monthly_documents = (string) ($plan->max_monthly_documents ?? '');
        $this->has_inventory = $plan->has_inventory;
        $this->has_treasury = $plan->has_treasury;
        $this->has_fixed_assets = $plan->has_fixed_assets;
        $this->has_multi_company = $plan->has_multi_company;
        $this->is_public = $plan->is_public;
        $this->is_active = $plan->is_active;
        $this->sort_order = (string) $plan->sort_order;
        $this->showForm = true;
        $this->resetValidation();
    }

    public function save(): void
    {
        $data = $this->validate([
            // Único en toda la tabla, no por empresa: los planes son del
            // proveedor y no llevan company_id.
            'code' => ['required', 'string', 'max:30', Rule::unique('plans', 'code')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => ['required', 'numeric', 'min:0'],
            'interval' => ['required', Rule::in(['monthly', 'yearly'])],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'max_companies' => ['nullable', 'integer', 'min:1'],
            'max_users' => ['nullable', 'integer', 'min:1'],
            'max_branches' => ['nullable', 'integer', 'min:1'],
            'max_monthly_documents' => ['nullable', 'integer', 'min:1'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ], attributes: [
            'code' => 'código',
            'name' => 'nombre',
            'price' => 'precio',
            'interval' => 'periodicidad',
            'trial_days' => 'días de prueba',
            'max_companies' => 'límite de empresas',
            'max_users' => 'límite de usuarios',
            'max_branches' => 'límite de sucursales',
            'max_monthly_documents' => 'límite de documentos',
            'sort_order' => 'orden',
        ]);

        // Los límites vacíos se guardan como NULL: significa «sin límite», y
        // así lo leen las cuotas.
        foreach (['max_companies', 'max_users', 'max_branches', 'max_monthly_documents'] as $limit) {
            $data[$limit] = $data[$limit] === '' || $data[$limit] === null ? null : (int) $data[$limit];
        }

        $data['description'] = trim($this->description) ?: null;
        $data += [
            'has_inventory' => $this->has_inventory,
            'has_treasury' => $this->has_treasury,
            'has_fixed_assets' => $this->has_fixed_assets,
            'has_multi_company' => $this->has_multi_company,
            'is_public' => $this->is_public,
            'is_active' => $this->is_active,
        ];

        if ($this->editingId === null) {
            $plan = new Plan;
        } else {
            $plan = Plan::query()->findOrFail($this->editingId);
        }

        $plan->fill($data)->save();

        session()->flash('success', 'Plan guardado. Los cambios aplican a las contrataciones nuevas; las suscripciones vigentes conservan sus condiciones.');

        $this->closeForm();
    }

    /**
     * Retira o reactiva el plan. **No hay borrar**: las suscripciones —vigentes
     * o históricas— lo referencian, y borrarlo dejaría facturas del servicio
     * apuntando a un plan que no existe.
     */
    public function toggleActive(int $id): void
    {
        $plan = Plan::query()->findOrFail($id);

        $plan->forceFill(['is_active' => ! $plan->is_active])->save();

        session()->flash('success', $plan->is_active
            ? 'El plan volvió a ofrecerse.'
            : 'Plan retirado: deja de ofrecerse, pero quien ya lo tiene sigue igual.');
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.admin.plan-index', [
            'plans' => Plan::query()
                ->withCount('subscriptions')
                ->orderBy('sort_order')
                ->orderBy('price')
                ->get(),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'code', 'name', 'description', 'price',
            'max_companies', 'max_users', 'max_branches', 'max_monthly_documents',
        ]);
        $this->interval = 'monthly';
        $this->trial_days = '30';
        $this->sort_order = '0';
        $this->has_inventory = true;
        $this->has_treasury = true;
        $this->has_fixed_assets = true;
        $this->has_multi_company = false;
        $this->is_public = true;
        $this->is_active = true;
    }
}
