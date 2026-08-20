<?php

declare(strict_types=1);

namespace App\Domains\Purchases\Models;

use App\Domains\Accounting\Models\Account;
use App\Domains\Catalog\Models\Product;
use App\Domains\Taxation\Models\Tax;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id', 'line_number', 'description', 'quantity', 'unit_price',
    'discount_rate', 'tax_id', 'expense_account_id',
])]
class PurchaseItem extends Model
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
        ];
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
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

    /** @return BelongsTo<Account, $this> */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
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
     * Va a inventario si el producto lleva control de existencias; si no, a una
     * cuenta de gasto.
     */
    public function goesToInventory(): bool
    {
        return $this->product?->track_inventory === true;
    }

    /**
     * Costo unitario neto de descuentos, que es el que debe entrar al kardex.
     */
    public function netUnitCost(): Money
    {
        $quantity = (string) $this->quantity;

        return bccomp($quantity, '0', 6) === 0
            ? Money::zero()
            : $this->subtotalAmount()->dividedBy($quantity);
    }
}
