<?php

declare(strict_types=1);

namespace App\Livewire\Taxation;

use App\Domains\Accounting\Models\Account;
use App\Domains\Taxation\Models\Tax;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Los impuestos con los que se factura.
 *
 * La tasa no está escrita en ningún servicio: Honduras cobra 15 % general y
 * 18 % sobre ciertos bienes, y esas cifras las cambia una ley, no un
 * despliegue. Esta pantalla es el lugar donde se cambian.
 *
 * ## Un impuesto no se elimina
 *
 * Las facturas emitidas congelan la tasa que se les aplicó, pero siguen
 * apuntando al impuesto. Borrarlo dejaría documentos huérfanos. Cuando el SAR
 * cambia una tasa, lo que se hace es **desactivar** la vieja y crear la nueva:
 * la vieja deja de ofrecerse en documentos nuevos y sigue explicando los viejos.
 */
#[Title('Impuestos')]
class TaxIndex extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $rate = '';

    public bool $is_included = false;

    public ?int $payable_account_id = null;

    public ?int $creditable_account_id = null;

    public bool $is_default = false;

    public bool $is_active = true;

    public function create(): void
    {
        $this->authorize('create', Tax::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $tax = Tax::query()->findOrFail($id);
        $this->authorize('update', $tax);

        $this->editingId = $tax->id;
        $this->code = $tax->code;
        $this->name = $tax->name;
        // Sin los ceros de la escala: se captura «15», no «15.000000».
        $this->rate = rtrim(rtrim((string) $tax->rate, '0'), '.');
        $this->is_included = $tax->is_included;
        $this->payable_account_id = $tax->payable_account_id;
        $this->creditable_account_id = $tax->creditable_account_id;
        $this->is_default = $tax->is_default;
        $this->is_active = $tax->is_active;
        $this->showForm = true;
        $this->resetValidation();
    }

    public function save(): void
    {
        $data = $this->validate([
            'code' => [
                'required', 'string', 'max:20',
                // El código identifica al impuesto dentro de la empresa; dos
                // «ISV15» harían imposible saber cuál aplicó una factura.
                Rule::unique('taxes', 'code')
                    ->where('company_id', app(CompanyContext::class)->idOrFail())
                    ->ignore($this->editingId),
            ],
            'name' => ['required', 'string', 'max:60'],
            // Cero es válido: las exoneraciones son un impuesto al 0 %, no la
            // ausencia de impuesto, y la factura tiene que poder decirlo.
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'payable_account_id' => ['nullable', 'integer'],
            'creditable_account_id' => ['nullable', 'integer'],
        ], attributes: [
            'code' => 'código',
            'name' => 'nombre',
            'rate' => 'tasa',
            'payable_account_id' => 'cuenta del impuesto cobrado',
            'creditable_account_id' => 'cuenta del impuesto acreditable',
        ]);

        $data['is_included'] = $this->is_included;
        $data['is_default'] = $this->is_default;
        $data['is_active'] = $this->is_active;

        if ($this->editingId === null) {
            $this->authorize('create', Tax::class);
            $tax = new Tax;
        } else {
            $tax = Tax::query()->findOrFail($this->editingId);
            $this->authorize('update', $tax);
        }

        $tax->fill($data)->save();

        // Predeterminado hay uno solo: si hubiera dos, el que se aplicaría
        // dependería del orden de la consulta.
        if ($tax->is_default) {
            Tax::query()->whereKeyNot($tax->id)->update(['is_default' => false]);
        }

        session()->flash('success', $this->editingId === null
            ? 'Impuesto configurado.'
            : 'Impuesto actualizado.');

        $this->closeForm();
    }

    /**
     * Desactivar es la baja: el impuesto deja de ofrecerse en documentos nuevos
     * y sigue explicando los que ya se emitieron con él.
     */
    public function toggleActive(int $id): void
    {
        $tax = Tax::query()->findOrFail($id);
        $this->authorize('update', $tax);

        $tax->forceFill([
            'is_active' => ! $tax->is_active,
            // Un impuesto inactivo no puede seguir siendo el predeterminado.
            'is_default' => $tax->is_active ? false : $tax->is_default,
        ])->save();

        session()->flash('success', $tax->is_active
            ? 'El impuesto quedó activo.'
            : 'El impuesto quedó inactivo. Las facturas que lo usaron no cambian.');
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
        $this->resetValidation();
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'code', 'name', 'rate', 'payable_account_id', 'creditable_account_id']);
        $this->is_included = false;
        $this->is_default = false;
        $this->is_active = true;
    }

    public function render(): View
    {
        $this->authorize('viewAny', Tax::class);

        return view('livewire.taxation.tax-index', [
            'taxes' => Tax::query()
                ->with(['payableAccount:id,code,name', 'creditableAccount:id,code,name'])
                ->orderBy('code')
                ->get(),
            'accounts' => $this->postableAccounts(),
        ]);
    }

    /**
     * @return Collection<int, Account>
     */
    private function postableAccounts(): Collection
    {
        return Account::query()
            ->where('is_postable', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }
}
