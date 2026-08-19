<?php

declare(strict_types=1);

namespace App\Livewire\Purchases;

use App\Domains\Partners\Models\Supplier;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Proveedores')]
class SupplierIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $trade_name = '';

    public string $tax_id = '';

    public string $type = 'company';

    public string $email = '';

    public string $phone = '';

    public string $address = '';

    public string $contact_name = '';

    public int $credit_days = 30;

    public bool $is_active = true;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('suppliers', 'code')
                    ->where('company_id', app(CompanyContext::class)->idOrFail())
                    ->ignore($this->editingId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:200'],
            'tax_id' => ['nullable', 'string', 'max:20'],
            'type' => ['required', 'in:individual,company'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'credit_days' => ['required', 'integer', 'min:0', 'max:365'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['code' => 'código', 'name' => 'nombre', 'tax_id' => 'RTN', 'credit_days' => 'días de crédito'];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->authorize('create', Supplier::class);

        $this->resetForm();
        $this->code = $this->nextCode();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $supplier = Supplier::query()->findOrFail($id);
        $this->authorize('update', $supplier);

        $this->editingId = $supplier->id;
        $this->code = $supplier->code;
        $this->name = $supplier->name;
        $this->trade_name = (string) $supplier->trade_name;
        $this->tax_id = (string) $supplier->tax_id;
        $this->type = $supplier->type;
        $this->email = (string) $supplier->email;
        $this->phone = (string) $supplier->phone;
        $this->address = (string) $supplier->address;
        $this->contact_name = (string) $supplier->contact_name;
        $this->credit_days = $supplier->credit_days;
        $this->is_active = $supplier->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate();

        if ($this->editingId !== null) {
            $supplier = Supplier::query()->findOrFail($this->editingId);
            $this->authorize('update', $supplier);
            $supplier->update($data);
            session()->flash('success', 'Proveedor actualizado.');
        } else {
            $this->authorize('create', Supplier::class);
            Supplier::create($data);
            session()->flash('success', 'Proveedor creado.');
        }

        $this->resetForm();
        $this->showForm = false;
    }

    public function delete(int $id): void
    {
        $supplier = Supplier::query()->findOrFail($id);
        $this->authorize('delete', $supplier);

        if ($supplier->purchases()->exists()) {
            session()->flash('error', 'No se puede eliminar un proveedor con compras. Desactívalo en su lugar.');

            return;
        }

        $supplier->delete();
        session()->flash('success', 'Proveedor eliminado.');
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function render(): View
    {
        $this->authorize('viewAny', Supplier::class);

        return view('livewire.purchases.supplier-index', [
            'suppliers' => Supplier::query()
                ->when($this->search !== '', fn ($q) => $q->search($this->search))
                ->orderBy('name')
                ->paginate(20),
        ]);
    }

    private function nextCode(): string
    {
        $last = Supplier::query()->where('code', 'like', 'PRV%')->orderByDesc('code')->value('code');
        $number = $last === null ? 1 : ((int) substr($last, 3)) + 1;

        return 'PRV'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'code', 'name', 'trade_name', 'tax_id',
            'email', 'phone', 'address', 'contact_name',
        ]);
        $this->type = 'company';
        $this->credit_days = 30;
        $this->is_active = true;
        $this->resetValidation();
    }
}
