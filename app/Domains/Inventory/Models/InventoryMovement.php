<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Policies\InventoryMovementPolicy;
use App\Domains\Tenancy\Models\Warehouse;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento de kardex. Inmutable: se escribe una vez y no se toca más.
 *
 * No declara `Fillable` a propósito. Solo `InventoryService` lo escribe, y lo
 * hace con `forceFill` dentro de la transacción que también actualiza la
 * existencia; permitir asignación masiva desde cualquier parte sería abrir la
 * puerta a un kardex escrito sin actualizar el saldo.
 *
 * @property string $quantity
 * @property string $unit_cost
 * @property string $total_value
 * @property MovementType $type
 */
#[UsePolicy(InventoryMovementPolicy::class)]
class InventoryMovement extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => MovementType::class,
            'quantity' => 'decimal:6',
            'unit_cost' => 'decimal:6',
            'total_value' => 'decimal:4',
            'balance_quantity' => 'decimal:6',
            'balance_value' => 'decimal:4',
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

    public function unitCost(): Money
    {
        return Money::of($this->unit_cost);
    }

    public function balanceValue(): Money
    {
        return Money::of($this->balance_value);
    }

    public function isInbound(): bool
    {
        return bccomp($this->quantity, '0', 6) > 0;
    }

    /**
     * Cantidad sin signo, para las columnas de entrada y salida del kardex.
     */
    public function absoluteQuantity(): string
    {
        return ltrim($this->quantity, '-');
    }

    /** @param  Builder<self>  $query */
    public function scopeForProduct(Builder $query, int $productId, ?int $warehouseId = null): void
    {
        $query->where('product_id', $productId)
            ->when($warehouseId !== null, fn (Builder $q) => $q->where('warehouse_id', $warehouseId));
    }

    /**
     * El kardex se lee en el orden en que se registraron los movimientos, que
     * es el orden en que se calcularon los saldos corridos. Ordenar por fecha
     * mostraría saldos que no progresan.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInKardexOrder(Builder $query): void
    {
        $query->orderBy('id');
    }
}
