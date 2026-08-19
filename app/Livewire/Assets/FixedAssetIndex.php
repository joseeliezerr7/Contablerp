<?php

declare(strict_types=1);

namespace App\Livewire\Assets;

use App\Domains\Assets\Enums\FixedAssetStatus;
use App\Domains\Assets\Exceptions\AssetException;
use App\Domains\Assets\Models\FixedAsset;
use App\Domains\Assets\Models\FixedAssetCategory;
use App\Domains\Assets\Services\FixedAssetService;
use App\Domains\Tenancy\Models\Branch;
use App\Support\Money;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Activos fijos')]
class FixedAssetIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'estado', except: '')]
    public string $statusFilter = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public ?int $branch_id = null;

    public ?int $fixed_asset_category_id = null;

    public string $code = '';

    public string $name = '';

    public string $serial_number = '';

    public string $location = '';

    public string $acquired_on = '';

    public string $cost = '';

    public string $salvage_value = '0.00';

    public string $useful_life_months = '';

    // Baja
    public ?int $disposingId = null;

    public string $disposed_on = '';

    public string $disposal_amount = '0.00';

    public string $disposal_reason = '';

    public ?int $proceeds_account_id = null;

    public function mount(): void
    {
        $this->acquired_on = now()->toDateString();
        $this->disposed_on = now()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter'], strict: true)) {
            $this->resetPage();
        }

        // Al elegir categoría se propone su vida útil, que es la de casi todos
        // los activos de esa clase.
        if ($property === 'fixed_asset_category_id' && $this->useful_life_months === '') {
            $category = FixedAssetCategory::query()->find($this->fixed_asset_category_id);
            $this->useful_life_months = (string) ($category?->useful_life_months ?? '');
        }
    }

    public function create(): void
    {
        $this->authorize('create', FixedAsset::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $asset = FixedAsset::query()->findOrFail($id);
        $this->authorize('update', $asset);

        $this->editingId = $asset->id;
        $this->branch_id = $asset->branch_id;
        $this->fixed_asset_category_id = $asset->fixed_asset_category_id;
        $this->code = $asset->code;
        $this->name = $asset->name;
        $this->serial_number = (string) $asset->serial_number;
        $this->location = (string) $asset->location;
        $this->acquired_on = $asset->acquired_on->toDateString();
        $this->cost = $asset->cost;
        $this->salvage_value = $asset->salvage_value;
        $this->useful_life_months = (string) $asset->useful_life_months;
        $this->showForm = true;
    }

    public function save(FixedAssetService $assets): void
    {
        $data = $this->validate([
            'branch_id' => ['nullable', 'integer'],
            'fixed_asset_category_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:200'],
            'serial_number' => ['nullable', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:120'],
            'acquired_on' => ['required', 'date'],
            'cost' => ['required', 'numeric', 'gt:0'],
            'salvage_value' => ['required', 'numeric', 'min:0'],
            'useful_life_months' => ['required', 'integer', 'min:1'],
        ], attributes: [
            'fixed_asset_category_id' => 'categoría',
            'acquired_on' => 'fecha de compra',
            'useful_life_months' => 'vida útil',
            'salvage_value' => 'valor residual',
        ]);

        try {
            if ($this->editingId === null) {
                $this->authorize('create', FixedAsset::class);
                $assets->create($data);
                session()->flash('success', 'Activo dado de alta.');
            } else {
                $asset = FixedAsset::query()->findOrFail($this->editingId);
                $this->authorize('update', $asset);
                $assets->update($asset, $data);
                session()->flash('success', 'Activo actualizado.');
            }

            $this->closeForm();
        } catch (AssetException $e) {
            $this->addError('cost', $e->getMessage());
        }
    }

    public function confirmDispose(int $id): void
    {
        $asset = FixedAsset::query()->findOrFail($id);
        $this->authorize('dispose', $asset);

        $this->disposingId = $id;
        $this->disposed_on = now()->toDateString();
        $this->disposal_amount = '0.00';
        $this->disposal_reason = '';
    }

    public function dispose(FixedAssetService $assets): void
    {
        $this->validate([
            'disposed_on' => ['required', 'date'],
            'disposal_amount' => ['required', 'numeric', 'min:0'],
            'disposal_reason' => ['required', 'string', 'min:5', 'max:500'],
        ], attributes: [
            'disposed_on' => 'fecha de baja',
            'disposal_amount' => 'importe recibido',
            'disposal_reason' => 'motivo',
        ]);

        $asset = FixedAsset::query()->findOrFail($this->disposingId);
        $this->authorize('dispose', $asset);

        try {
            $assets->dispose(
                $asset,
                $this->disposed_on,
                Money::of($this->disposal_amount),
                $this->disposal_reason,
                $this->proceeds_account_id,
            );

            session()->flash('success', 'Activo dado de baja y contabilizado.');
        } catch (AssetException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->cancelDispose();
    }

    public function cancelDispose(): void
    {
        $this->reset(['disposingId', 'disposal_amount', 'disposal_reason']);
        $this->resetValidation();
    }

    public function delete(int $id, FixedAssetService $assets): void
    {
        $asset = FixedAsset::query()->findOrFail($id);
        $this->authorize('delete', $asset);

        $assets->delete($asset);
        session()->flash('success', 'Activo eliminado.');
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
        $this->resetValidation();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'fixed_asset_category_id', 'code', 'name',
            'serial_number', 'location', 'cost', 'useful_life_months',
        ]);
        $this->acquired_on = now()->toDateString();
        $this->salvage_value = '0.00';
    }

    public function render(): View
    {
        $this->authorize('viewAny', FixedAsset::class);

        $query = FixedAsset::query()
            ->with(['category:id,code,name', 'branch:id,name'])
            ->when($this->search !== '', fn ($q) => $q->search($this->search))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter));

        $active = (clone $query)->where('status', '!=', FixedAssetStatus::Disposed);

        return view('livewire.assets.fixed-asset-index', [
            'assets' => $query->orderBy('code')->paginate(25),
            'statuses' => FixedAssetStatus::cases(),
            'categories' => FixedAssetCategory::query()->active()->orderBy('code')->get(),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'totalCost' => Money::of((string) (clone $active)->sum('cost')),
            'totalBookValue' => Money::of((string) (clone $active)->sum('book_value')),
        ]);
    }
}
