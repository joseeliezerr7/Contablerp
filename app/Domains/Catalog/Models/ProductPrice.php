<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $product_id
 * @property int $price_list_id
 * @property string $price
 */
#[Fillable(['product_id', 'price_list_id', 'price'])]
class ProductPrice extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['price' => 'decimal:4'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function amount(): Money
    {
        return Money::of($this->price);
    }
}
