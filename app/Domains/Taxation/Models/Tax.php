<?php

declare(strict_types=1);

namespace App\Domains\Taxation\Models;

use App\Domains\Accounting\Models\Account;
use App\Domains\Taxation\Policies\TaxPolicy;
use App\Support\Tenancy\BelongsToCompany;
use Database\Factories\TaxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $rate Porcentaje, p. ej. '15.000000'
 * @property bool $is_included
 */
#[UseFactory(TaxFactory::class)]
#[Fillable([
    'code', 'name', 'rate', 'is_included',
    'payable_account_id', 'creditable_account_id', 'is_default', 'is_active',
])]
#[UsePolicy(TaxPolicy::class)]
class Tax extends Model
{
    /** @use HasFactory<TaxFactory> */
    use BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return [
            // decimal:6 devuelve string, que es lo que necesita bcmath.
            'rate' => 'decimal:6',
            'is_included' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payable_account_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function creditableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'creditable_account_id');
    }

    public function label(): string
    {
        return sprintf('%s (%s%%)', $this->name, rtrim(rtrim((string) $this->rate, '0'), '.'));
    }

    public function isZeroRated(): bool
    {
        return bccomp((string) $this->rate, '0', 6) === 0;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
