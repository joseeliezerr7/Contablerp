<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Policies\InventoryStockPolicy;
use App\Domains\Tenancy\Models\Warehouse;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Existencia de un producto en una bodega.
 *
 * Materializa el kardex. `average_cost` es una columna generada a partir de
 * `total_value / quantity`: el promedio nunca se guarda, siempre se deriva.
 *
 * @property string $quantity
 * @property string $total_value
 * @property string $average_cost
 */
#[UsePolicy(InventoryStockPolicy::class)]
#[Fillable(['warehouse_id', 'product_id', 'min_quantity', 'max_quantity'])]
class InventoryStock extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'total_value' => 'decimal:4',
            'average_cost' => 'decimal:6',
            'min_quantity' => 'decimal:6',
            'max_quantity' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function valueAmount(): Money
    {
        return Money::of($this->total_value);
    }

    public function averageCost(): Money
    {
        return Money::of($this->average_cost);
    }

    /**
     * Existencia por debajo del mínimo configurado.
     *
     * `min_quantity` en cero significa «sin punto de reorden», no «alertar
     * siempre»: de lo contrario todo producto recién creado aparecería en el
     * reporte de faltantes.
     */
    public function isBelowMinimum(): bool
    {
        return bccomp($this->min_quantity, '0', 6) > 0
            && bccomp($this->quantity, $this->min_quantity, 6) < 0;
    }

    /** @param  Builder<self>  $query */
    public function scopeWithStock(Builder $query): void
    {
        $query->where('quantity', '>', 0);
    }

    /** @param  Builder<self>  $query */
    public function scopeBelowMinimum(Builder $query): void
    {
        $query->where('min_quantity', '>', 0)
            ->whereColumn('quantity', '<', 'min_quantity');
    }
}
