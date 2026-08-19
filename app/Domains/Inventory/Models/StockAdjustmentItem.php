<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Catalog\Models\Product;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $quantity
 * @property string $unit_cost
 * @property string $total_value
 */
class StockAdjustmentItem extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'unit_cost' => 'decimal:6',
            'total_value' => 'decimal:4',
            'line_number' => 'integer',
        ];
    }

    /** @return BelongsTo<StockAdjustment, $this> */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function valueAmount(): Money
    {
        return Money::of($this->total_value);
    }

    public function isIncrease(): bool
    {
        return bccomp($this->quantity, '0', 6) > 0;
    }
}
