<?php

declare(strict_types=1);

namespace App\Domains\Sales\Models;

use App\Domains\Catalog\Models\Product;
use App\Domains\Taxation\Models\Tax;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $line_number
 * @property string $description
 * @property string $quantity
 * @property string $unit_price
 */
#[Fillable([
    'product_id', 'line_number', 'description', 'quantity', 'unit_price',
    'discount_rate', 'tax_id',
])]
class SaleItem extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:6',
            'unit_price' => 'decimal:6',
            'discount_rate' => 'decimal:6',
            'discount_amount' => 'decimal:4',
            'tax_rate' => 'decimal:6',
            'tax_amount' => 'decimal:4',
            'subtotal' => 'decimal:4',
            'total' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'cost_total' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Tax, $this> */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    /**
     * Precio unitario tal como se pactó, con sus seis decimales.
     *
     * Se redondea solo al presentarlo: un precio de lista puede llevar más de
     * dos decimales —el kilo a 12.3456— y redondearlo aquí cambiaría el importe
     * que se muestra respecto del que se cobró.
     */
    public function unitPriceAmount(): Money
    {
        return Money::of($this->unit_price);
    }

    public function subtotalAmount(): Money
    {
        return Money::of($this->subtotal);
    }

    public function taxAmount(): Money
    {
        return Money::of($this->tax_amount);
    }

    public function discountAmount(): Money
    {
        return Money::of($this->discount_amount);
    }

    public function totalAmount(): Money
    {
        return Money::of($this->total);
    }

    /**
     * Costo total de la línea: el importe exacto que el kardex descargó.
     *
     * No se recalcula multiplicando `unit_cost` por la cantidad. El costo
     * unitario es un cociente ya redondeado, y volver a multiplicarlo puede dar
     * un centavo distinto al que salió del inventario —con lo que la partida
     * contable y el kardex acreditarían importes diferentes contra la misma
     * cuenta—.
     */
    public function costAmount(): Money
    {
        return Money::of($this->cost_total);
    }
}
