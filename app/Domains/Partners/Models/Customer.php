<?php

declare(strict_types=1);

namespace App\Domains\Partners\Models;

use App\Domains\Catalog\Models\PriceList;
use App\Domains\Partners\Policies\CustomerPolicy;
use App\Domains\Receivables\Models\Receivable;
use App\Domains\Sales\Models\Sale;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Database\Factories\CustomerFactory;
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
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $tax_id
 * @property string $credit_limit
 * @property int $credit_days
 */
#[UseFactory(CustomerFactory::class)]
#[UsePolicy(CustomerPolicy::class)]
#[Fillable([
    'code', 'name', 'trade_name', 'tax_id', 'type',
    'email', 'phone', 'address', 'city',
    'price_list_id', 'credit_limit', 'credit_days', 'is_active', 'is_walk_in', 'notes',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:4',
            'credit_days' => 'integer',
            'is_active' => 'boolean',
            'is_walk_in' => 'boolean',
        ];
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** @return HasMany<Receivable, $this> */
    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }

    public function label(): string
    {
        return "{$this->code} — {$this->name}";
    }

    public function displayName(): string
    {
        return $this->trade_name ?: $this->name;
    }

    public function creditLimit(): Money
    {
        return Money::of($this->credit_limit);
    }

    public function hasCredit(): bool
    {
        return $this->creditLimit()->isPositive();
    }

    /**
     * Saldo pendiente del cliente: la suma de sus cuentas por cobrar abiertas.
     */
    public function outstandingBalance(): Money
    {
        $total = $this->receivables()
            ->where('status', 'open')
            ->sum('balance');

        return Money::of((string) $total);
    }

    /**
     * Lista de precios que aplica: la suya o la predeterminada de la empresa.
     */
    public function effectivePriceListId(): ?int
    {
        return $this->price_list_id ?? PriceList::default()?->id;
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
                ->orWhere('name', 'like', '%'.$term.'%')
                ->orWhere('trade_name', 'like', '%'.$term.'%')
                ->orWhere('tax_id', 'like', $term.'%');
        });
    }
}
