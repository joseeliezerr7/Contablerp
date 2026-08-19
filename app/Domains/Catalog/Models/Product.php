<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Accounting\Models\Account;
use App\Domains\Catalog\Policies\ProductPolicy;
use App\Domains\Taxation\Models\Tax;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Producto o servicio del catálogo.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property bool $track_inventory
 */
#[UseFactory(ProductFactory::class)]
#[UsePolicy(ProductPolicy::class)]
#[Fillable([
    'code', 'barcode', 'name', 'description', 'type',
    'unit_id', 'product_category_id', 'tax_id', 'cost',
    'track_inventory', 'is_active',
    'income_account_id', 'cost_account_id', 'inventory_account_id',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:6',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /** @return BelongsTo<Tax, $this> */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    /** @return HasMany<ProductPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'income_account_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function costAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cost_account_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function inventoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'inventory_account_id');
    }

    public function label(): string
    {
        return "{$this->code} — {$this->name}";
    }

    public function isService(): bool
    {
        return $this->type === 'service';
    }

    /**
     * Precio del producto en una lista. Devuelve null si no está listado, para
     * que quien pregunte decida qué hacer en vez de recibir un cero engañoso.
     */
    public function priceIn(?int $priceListId): ?Money
    {
        if ($priceListId === null) {
            return null;
        }

        $price = $this->relationLoaded('prices')
            ? $this->prices->firstWhere('price_list_id', $priceListId)
            : $this->prices()->where('price_list_id', $priceListId)->first();

        return $price?->amount();
    }

    public function costAmount(): Money
    {
        return Money::of($this->cost);
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<self>  $query */
    public function scopeSearch(Builder $query, string $term): void
    {
        $query->where(function (Builder $q) use ($term): void {
            $q->where('code', 'like', $term.'%')
                ->orWhere('barcode', $term)
                ->orWhere('name', 'like', '%'.$term.'%');
        });
    }
}
