<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Models\InventoryMovement;
use App\Domains\Tenancy\Models\Warehouse;
use App\Support\Money;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kardex de un producto: entradas, salidas y saldo corrido.
 *
 * Se lee en el orden en que se registraron los movimientos, que es el orden en
 * que se calcularon los saldos. Ordenarlo por fecha mostraría una columna de
 * saldo que no progresa.
 */
#[Title('Kardex')]
class KardexView extends Component
{
    #[Url(as: 'producto', except: '')]
    public string $productId = '';

    #[Url(as: 'bodega', except: '')]
    public string $warehouseId = '';

    #[Url(as: 'desde', except: '')]
    public string $from = '';

    #[Url(as: 'hasta', except: '')]
    public string $to = '';

    public function render(): View
    {
        $this->authorize('viewAny', InventoryMovement::class);

        $product = $this->productId === ''
            ? null
            : Product::query()->find($this->productId);

        $movements = collect();

        if ($product !== null) {
            $movements = InventoryMovement::query()
                ->with('warehouse:id,code')
                ->where('product_id', $product->id)
                ->when($this->warehouseId !== '', fn ($q) => $q->where('warehouse_id', $this->warehouseId))
                ->when($this->from !== '', fn ($q) => $q->where('date', '>=', $this->from))
                ->when($this->to !== '', fn ($q) => $q->where('date', '<=', $this->to))
                ->inKardexOrder()
                ->limit(500)
                ->get();
        }

        return view('livewire.inventory.kardex-view', [
            'product' => $product,
            'movements' => $movements,
            'products' => Product::query()->where('track_inventory', true)
                ->active()->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(['id', 'code', 'name']),
            'inTotal' => Money::sum(
                $movements->filter(fn (InventoryMovement $m) => $m->isInbound())
                    ->map(fn (InventoryMovement $m) => $m->valueAmount())->all()
            ),
            'outTotal' => Money::sum(
                $movements->reject(fn (InventoryMovement $m) => $m->isInbound())
                    ->map(fn (InventoryMovement $m) => $m->valueAmount())->all()
            ),
        ]);
    }
}
