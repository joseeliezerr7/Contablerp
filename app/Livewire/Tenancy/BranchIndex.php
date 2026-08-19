<?php

declare(strict_types=1);

namespace App\Livewire\Tenancy;

use App\Domains\Tenancy\Models\Branch;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Sucursales')]
class BranchIndex extends Component
{
    public ?int $editingId = null;

    public bool $showForm = false;

    public string $code = '';

    public string $name = '';

    public string $address = '';

    public string $phone = '';

    public bool $is_main = false;

    public bool $is_active = true;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:10',
                Rule::unique('branches', 'code')
                    ->where('company_id', app(CompanyContext::class)->idOrFail())
                    ->ignore($this->editingId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_main' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['code' => 'código', 'name' => 'nombre'];
    }

    public function create(): void
    {
        $this->authorize('create', Branch::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        // El scope global ya limita la búsqueda a la empresa activa.
        $branch = Branch::query()->findOrFail($id);
        $this->authorize('update', $branch);

        $this->editingId = $branch->id;
        $this->code = $branch->code;
        $this->name = $branch->name;
        $this->address = (string) $branch->address;
        $this->phone = (string) $branch->phone;
        $this->is_main = $branch->is_main;
        $this->is_active = $branch->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate();

        DB::transaction(function () use ($data): void {
            if ($this->editingId !== null) {
                $branch = Branch::query()->findOrFail($this->editingId);
                $this->authorize('update', $branch);
                $branch->update($data);
            } else {
                $this->authorize('create', Branch::class);
                $branch = Branch::create($data);
            }

            // Solo puede haber una casa matriz por empresa.
            if ($branch->is_main) {
                Branch::query()
                    ->whereKeyNot($branch->id)
                    ->where('is_main', true)
                    ->update(['is_main' => false]);
            }
        });

        session()->flash('success', $this->editingId !== null ? 'Sucursal actualizada.' : 'Sucursal creada.');

        $this->resetForm();
        $this->showForm = false;
    }

    public function delete(int $id): void
    {
        $branch = Branch::query()->findOrFail($id);
        $this->authorize('delete', $branch);

        if ($branch->warehouses()->exists()) {
            session()->flash('error', 'No se puede eliminar una sucursal que tiene bodegas asignadas.');

            return;
        }

        $branch->delete();
        session()->flash('success', 'Sucursal eliminada.');
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function render(): View
    {
        return view('livewire.tenancy.branch-index', [
            'branches' => Branch::query()
                ->withCount('warehouses')
                ->orderBy('code')
                ->get(),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'code', 'name', 'address', 'phone', 'is_main']);
        $this->is_active = true;
        $this->resetValidation();
    }
}
