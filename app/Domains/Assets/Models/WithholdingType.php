<?php

declare(strict_types=1);

namespace App\Domains\Assets\Models;

use App\Domains\Accounting\Models\Account;
use App\Domains\Assets\Enums\WithholdingKind;
use App\Domains\Assets\Enums\WithholdingScope;
use App\Domains\Assets\Policies\WithholdingTypePolicy;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $rate
 * @property WithholdingKind $kind
 * @property WithholdingScope $applies_to
 */
#[UsePolicy(WithholdingTypePolicy::class)]
#[Fillable(['code', 'name', 'kind', 'base', 'rate', 'applies_to', 'account_id', 'is_active'])]
class WithholdingType extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'kind' => WithholdingKind::class,
            'applies_to' => WithholdingScope::class,
            'rate' => 'decimal:6',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Importe retenido sobre una base.
     */
    public function compute(Money $base): Money
    {
        return $base->percent($this->rate)->round(Money::SCALE);
    }

    public function label(): string
    {
        return $this->code.' — '.$this->name.' ('.rtrim(rtrim($this->rate, '0'), '.').' %)';
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<self>  $query */
    public function scopeForPurchases(Builder $query): void
    {
        $query->where('applies_to', WithholdingScope::Purchase);
    }

    /** @param  Builder<self>  $query */
    public function scopeForSales(Builder $query): void
    {
        $query->where('applies_to', WithholdingScope::Sale);
    }
}
