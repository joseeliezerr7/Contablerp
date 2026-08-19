<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domains\Inventory\Models\InventoryStock;
use App\Domains\Tenancy\Models\Warehouse;
use App\Support\Money;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Existencias por producto y bodega, con su valor.
 *
 * El total que se muestra es el mismo número que debe estar en la cuenta
 * contable de inventario; que se vea aquí es intencional, porque es el primer
 * sitio donde alguien nota que algo no cuadra.
 */
#[Title('Existencias')]
class StockIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'bodega', except: '')]
    public string $warehouseFilter = '';

    #[Url(as: 'bajas', except: false)]
    public bool $belowMinimumOnly = false;

    public ?int $editingId = null;

    public string $minQuantity = '0';

    public string $maxQuantity = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'warehouseFilter', 'belowMinimumOnly'], strict: true)) {
            $this->resetPage();
        }
    }

    public function editReorder(int $id): void
    {
        $stock = InventoryStock::query()->findOrFail($id);
        $this->authorize('update', $stock);

        $this->editingId = $id;
        $this->minQuantity = trim($stock->min_quantity);
        $this->maxQuantity = $stock->max_quantity === null ? '' : trim($stock->max_quantity);
    }

    public function saveReorder(): void
    {
        $this->validate([
            'minQuantity' => ['required', 'numeric', 'gte:0'],
            'maxQuantity' => ['nullable', 'numeric', 'gte:0'],
        ], attributes: ['minQuantity' => 'mínimo', 'maxQuantity' => 'máximo']);

        $stock = InventoryStock::query()->findOrFail($this->editingId);
        $this->authorize('update', $stock);

        $stock->forceFill([
            'min_quantity' => $this->minQuantity,
            'max_quantity' => $this->maxQuantity === '' ? null : $this->maxQuantity,
        ])->save();

        session()->flash('success', 'Puntos de reorden actualizados.');

        $this->cancelReorder();
    }

    public function cancelReorder(): void
    {
        $this->reset(['editingId', 'minQuantity', 'maxQuantity']);
        $this->resetValidation();
    }

    public function render(): View
    {
        $this->authorize('viewAny', InventoryStock::class);

        $query = InventoryStock::query()
            ->with(['product:id,code,name,unit_id', 'product.unit:id,code', 'warehouse:id,code,name'])
            ->when($this->search !== '', fn ($q) => $q->whereHas(
                'product', fn ($p) => $p->search($this->search)
            ))
            ->when($this->warehouseFilter !== '', fn ($q) => $q->where('warehouse_id', $this->warehouseFilter))
            ->when($this->belowMinimumOnly, fn ($q) => $q->belowMinimum());

        $total = (clone $query)->sum('total_value');

        return view('livewire.inventory.stock-index', [
            'stocks' => $query
                ->orderByDesc('total_value')
                ->paginate(30),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(['id', 'code', 'name']),
            'totalValue' => Money::of((string) $total),
        ]);
    }
}
