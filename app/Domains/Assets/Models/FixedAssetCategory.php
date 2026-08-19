<?php

declare(strict_types=1);

namespace App\Domains\Assets\Models;

use App\Domains\Accounting\Models\Account;
use App\Domains\Assets\Policies\FixedAssetCategoryPolicy;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoría de activo fijo: un edificio y una computadora no van a la misma
 * cuenta ni duran lo mismo.
 */
#[Fillable([
    'code', 'name', 'useful_life_months',
    'asset_account_id', 'depreciation_account_id', 'accumulated_account_id', 'is_active',
])]
#[UsePolicy(FixedAssetCategoryPolicy::class)]
class FixedAssetCategory extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'useful_life_months' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<FixedAsset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function depreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_account_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function accumulatedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_account_id');
    }

    public function label(): string
    {
        return $this->code.' — '.$this->name;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
