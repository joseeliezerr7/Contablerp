<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan del servicio.
 *
 * **No lleva `company_id` ni `BelongsToCompany`.** Es la primera entidad del
 * sistema que pertenece al proveedor y no a una empresa cliente, así que queda
 * deliberadamente fuera del filtro de empresa que gobierna todo lo demás.
 *
 * @property string $price
 * @property int|null $max_companies
 */
#[Fillable([
    'code', 'name', 'description', 'price', 'currency_code', 'interval', 'trial_days',
    'max_companies', 'max_users', 'max_branches', 'max_monthly_documents',
    'has_inventory', 'has_treasury', 'has_fixed_assets', 'has_multi_company',
    'is_public', 'is_active', 'sort_order',
])]
class Plan extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'trial_days' => 'integer',
            'max_companies' => 'integer',
            'max_users' => 'integer',
            'max_branches' => 'integer',
            'max_monthly_documents' => 'integer',
            'has_inventory' => 'boolean',
            'has_treasury' => 'boolean',
            'has_fixed_assets' => 'boolean',
            'has_multi_company' => 'boolean',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function priceAmount(): Money
    {
        return Money::of($this->price);
    }

    public function isFree(): bool
    {
        return $this->priceAmount()->isZero();
    }

    public function label(): string
    {
        return $this->name;
    }

    /**
     * Precio mensualizado, para comparar planes y calcular el ingreso
     * recurrente sin que un plan anual lo distorsione.
     */
    public function monthlyPrice(): Money
    {
        return $this->interval === 'yearly'
            ? $this->priceAmount()->dividedBy(12)
            : $this->priceAmount();
    }

    /** @param  Builder<self>  $query */
    public function scopePublic(Builder $query): void
    {
        $query->where('is_active', true)->where('is_public', true);
    }
}
