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
 * @property string $quantity
 */
#[Fillable([
    'sale_item_id', 'product_id', 'line_number', 'description',
    'quantity', 'unit_price', 'discount_rate', 'tax_id',
])]
class CreditNoteItem extends Model
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

    /** @return BelongsTo<CreditNote, $this> */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    /** @return BelongsTo<SaleItem, $this> */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
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
     * Costo con el que la mercadería vuelve al inventario. Se copia de la línea
     * de la factura y no del promedio de hoy: la salida se valoró a aquel costo
     * y la entrada tiene que devolver el mismo importe a la cuenta.
     */
    public function costAmount(): Money
    {
        return Money::of($this->cost_total);
    }
}
