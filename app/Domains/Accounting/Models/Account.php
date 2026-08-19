<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Models;

use App\Domains\Accounting\Enums\AccountNature;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\CashFlowClass;
use App\Domains\Accounting\Policies\AccountPolicy;
use App\Support\Tenancy\BelongsToCompany;
use Database\Factories\AccountFactory;
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
 * Cuenta del plan contable.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $parent_id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property AccountNature $nature
 * @property int $level
 * @property bool $is_postable
 * @property bool $is_system
 * @property bool $is_active
 * @property string $path
 */
#[UseFactory(AccountFactory::class)]
#[UsePolicy(AccountPolicy::class)]
#[Fillable([
    'parent_id', 'code', 'name', 'type', 'nature', 'cash_flow_class',
    'is_cash_equivalent', 'requires_partner', 'requires_branch', 'currency_code', 'is_active',
])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'nature' => AccountNature::class,
            'cash_flow_class' => CashFlowClass::class,
            'level' => 'integer',
            'is_postable' => 'boolean',
            'is_system' => 'boolean',
            'is_cash_equivalent' => 'boolean',
            'requires_partner' => 'boolean',
            'requires_branch' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    /** @return HasMany<JournalEntryLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /** @return HasMany<AccountBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(AccountBalance::class);
    }

    public function label(): string
    {
        return "{$this->code} — {$this->name}";
    }

    /**
     * Puede recibir movimientos: es hoja, está marcada como imputable y activa.
     */
    public function acceptsPostings(): bool
    {
        return $this->is_postable && $this->is_active;
    }

    /**
     * Ruta materializada de esta cuenta, derivada de la del padre.
     */
    public function buildPath(?self $parent): string
    {
        return $parent === null ? $this->code : $parent->path.'/'.$this->code;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<self>  $query */
    public function scopePostable(Builder $query): void
    {
        $query->where('is_postable', true)->where('is_active', true);
    }

    /** @param  Builder<self>  $query */
    public function scopeOfType(Builder $query, AccountType $type): void
    {
        $query->where('type', $type);
    }

    /** @param  Builder<self>  $query */
    public function scopeCash(Builder $query): void
    {
        $query->where('is_cash_equivalent', true);
    }

    /**
     * Toda la rama que cuelga de esta cuenta, ella incluida.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUnder(Builder $query, self $account): void
    {
        $query->where(function (Builder $q) use ($account): void {
            $q->whereKey($account->id)
                ->orWhere('path', 'like', $account->path.'/%');
        });
    }
}
