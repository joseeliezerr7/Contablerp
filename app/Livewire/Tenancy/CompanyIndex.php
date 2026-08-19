<?php

declare(strict_types=1);

namespace App\Livewire\Tenancy;

use App\Domains\Tenancy\Models\Company;
use App\Domains\Tenancy\Services\CompanyService;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Empresas')]
class CompanyIndex extends Component
{
    public ?int $editingId = null;

    public bool $showForm = false;

    public string $legal_name = '';

    public string $trade_name = '';

    public string $tax_id = '';

    public string $address = '';

    public string $phone = '';

    public string $email = '';

    public string $currency_code = 'HNL';

    public int $fiscal_year_start_month = 1;

    public bool $is_active = true;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:200'],
            'tax_id' => [
                'required', 'string', 'max:20',
                // El RTN es único dentro del tenant, no globalmente: dos cuentas
                // SaaS distintas pueden llevar la contabilidad de la misma empresa.
                Rule::unique('companies', 'tax_id')
                    ->where('tenant_id', auth()->user()->tenant_id)
                    ->ignore($this->editingId)
                    ->whereNull('deleted_at'),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'currency_code' => ['required', 'string', 'size:3'],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'legal_name' => 'razón social',
            'trade_name' => 'nombre comercial',
            'tax_id' => 'RTN',
            'currency_code' => 'moneda',
            'fiscal_year_start_month' => 'mes de inicio del ejercicio',
        ];
    }

    public function create(): void
    {
        $this->authorize('create', Company::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $company = $this->findAccessibleCompany($id);
        $this->authorize('update', $company);

        $this->editingId = $company->id;
        $this->legal_name = $company->legal_name;
        $this->trade_name = (string) $company->trade_name;
        $this->tax_id = $company->tax_id;
        $this->address = (string) $company->address;
        $this->phone = (string) $company->phone;
        $this->email = (string) $company->email;
        $this->currency_code = $company->currency_code;
        $this->fiscal_year_start_month = $company->fiscal_year_start_month;
        $this->is_active = $company->is_active;
        $this->showForm = true;
    }

    public function save(CompanyService $service): void
    {
        $data = $this->validate();

        if ($this->editingId !== null) {
            $company = $this->findAccessibleCompany($this->editingId);
            $this->authorize('update', $company);
            $service->update($company, $data);

            session()->flash('success', 'Empresa actualizada.');
        } else {
            $this->authorize('create', Company::class);
            $service->create($data, auth()->user());

            session()->flash('success', 'Empresa creada con su sucursal principal y bodega por defecto.');
        }

        $this->resetForm();
        $this->showForm = false;
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function render(): View
    {
        return view('livewire.tenancy.company-index', [
            'companies' => auth()->user()->companies()
                ->orderBy('legal_name')
                ->get(),
        ]);
    }

    /**
     * Solo se pueden editar empresas a las que el usuario tiene acceso. El id
     * llega del cliente, así que la búsqueda se hace sobre su relación.
     */
    private function findAccessibleCompany(int $id): Company
    {
        return auth()->user()->companies()->whereKey($id)->firstOrFail();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'legal_name', 'trade_name', 'tax_id',
            'address', 'phone', 'email',
        ]);
        $this->currency_code = 'HNL';
        $this->fiscal_year_start_month = 1;
        $this->is_active = true;
        $this->resetValidation();
    }
}
