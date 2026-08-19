<?php

declare(strict_types=1);

namespace App\Domains\Partners\Models;

use App\Domains\Partners\Policies\SupplierPolicy;
use App\Domains\Payables\Models\Payable;
use App\Domains\Purchases\Models\Purchase;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $credit_days
 */
#[UseFactory(SupplierFactory::class)]
#[UsePolicy(SupplierPolicy::class)]
#[Fillable([
    'code', 'name', 'trade_name', 'tax_id', 'type',
    'email', 'phone', 'address', 'city', 'contact_name',
    'credit_days', 'is_active', 'notes',
])]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'credit_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Purchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /** @return HasMany<Payable, $this> */
    public function payables(): HasMany
    {
        return $this->hasMany(Payable::class);
    }

    public function label(): string
    {
        return "{$this->code} — {$this->name}";
    }

    public function displayName(): string
    {
        return $this->trade_name ?: $this->name;
    }

    /**
     * Lo que la empresa le debe a este proveedor.
     */
    public function outstandingBalance(): Money
    {
        return Money::of((string) $this->payables()->where('status', 'open')->sum('balance'));
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
