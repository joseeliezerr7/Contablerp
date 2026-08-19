<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\AccountMapping;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Fiscal\Models\FiscalPoint;
use App\Domains\Tenancy\Policies\CompanyPolicy;
use App\Models\User;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Empresa contable. Unidad de aislamiento de todo el sistema.
 *
 * No usa BelongsToCompany: este modelo *es* la empresa. Su aislamiento se
 * resuelve por pertenencia del usuario (tabla company_user), no por scope.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $legal_name
 * @property string|null $trade_name
 * @property string $tax_id
 * @property string $currency_code
 * @property bool $is_active
 */
#[UseFactory(CompanyFactory::class)]
#[UsePolicy(CompanyPolicy::class)]
#[Fillable([
    'tenant_id', 'legal_name', 'trade_name', 'tax_id', 'address', 'phone', 'email',
    'logo_path', 'country_code', 'currency_code', 'locale',
    'fiscal_year_start_month', 'decimal_places', 'is_active',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'fiscal_year_start_month' => 'integer',
            'decimal_places' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<Branch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /** @return HasMany<Warehouse, $this> */
    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    /** @return HasMany<FiscalPoint, $this> */
    public function fiscalPoints(): HasMany
    {
        return $this->hasMany(FiscalPoint::class);
    }

    /** @return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** @return HasMany<AccountMapping, $this> */
    public function accountMappings(): HasMany
    {
        return $this->hasMany(AccountMapping::class);
    }

    /** @return HasMany<FiscalYear, $this> */
    public function fiscalYears(): HasMany
    {
        return $this->hasMany(FiscalYear::class);
    }

    /** @return HasMany<JournalEntry, $this> */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('branch_id')
            ->withTimestamps();
    }

    /** @return BelongsTo<Branch, $this>|null */
    public function mainBranch(): ?Branch
    {
        return $this->branches()->where('is_main', true)->first()
            ?? $this->branches()->first();
    }

    public function displayName(): string
    {
        return $this->trade_name ?: $this->legal_name;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
