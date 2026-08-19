<?php

declare(strict_types=1);

namespace App\Livewire\Tenancy;

use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\Rules\BelongsToCurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Bodegas')]
class WarehouseIndex extends Component
{
    public ?int $editingId = null;

    public bool $showForm = false;

    public string $code = '';

    public string $name = '';

    public ?int $branch_id = null;

    public bool $is_default = false;

    public bool $is_active = true;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:10',
                Rule::unique('warehouses', 'code')
                    ->where('company_id', app(CompanyContext::class)->idOrFail())
                    ->ignore($this->editingId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:150'],
            // `exists:branches,id` aceptaría la sucursal de otra empresa: el id
            // llega del cliente y el scope global no filtra reglas de validación.
            'branch_id' => ['required', 'integer', new BelongsToCurrentCompany('branches')],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['code' => 'código', 'name' => 'nombre', 'branch_id' => 'sucursal'];
    }

    public function create(): void
    {
        $this->authorize('create', Warehouse::class);

        $this->resetForm();
        $this->branch_id = Branch::query()->where('is_main', true)->value('id')
            ?? Branch::query()->value('id');
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $warehouse = Warehouse::query()->findOrFail($id);
        $this->authorize('update', $warehouse);

        $this->editingId = $warehouse->id;
        $this->code = $warehouse->code;
        $this->name = $warehouse->name;
        $this->branch_id = $warehouse->branch_id;
        $this->is_default = $warehouse->is_default;
        $this->is_active = $warehouse->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate();

        DB::transaction(function () use ($data): void {
            if ($this->editingId !== null) {
                $warehouse = Warehouse::query()->findOrFail($this->editingId);
                $this->authorize('update', $warehouse);
                $warehouse->update($data);
            } else {
                $this->authorize('create', Warehouse::class);
                $warehouse = Warehouse::create($data);
            }

            if ($warehouse->is_default) {
                Warehouse::query()
                    ->whereKeyNot($warehouse->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });

        session()->flash('success', $this->editingId !== null ? 'Bodega actualizada.' : 'Bodega creada.');

        $this->resetForm();
        $this->showForm = false;
    }

    public function delete(int $id): void
    {
        $warehouse = Warehouse::query()->findOrFail($id);
        $this->authorize('delete', $warehouse);

        $warehouse->delete();
        session()->flash('success', 'Bodega eliminada.');
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function render(): View
    {
        return view('livewire.tenancy.warehouse-index', [
            'warehouses' => Warehouse::query()->with('branch')->orderBy('code')->get(),
            'branches' => Branch::query()->active()->orderBy('code')->get(),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'code', 'name', 'branch_id', 'is_default']);
        $this->is_active = true;
        $this->resetValidation();
    }
}
