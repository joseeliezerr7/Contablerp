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
class StockTransferItem extends Model
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

    /** @return BelongsTo<StockTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
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
}
