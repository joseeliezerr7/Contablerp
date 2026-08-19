<?php

declare(strict_types=1);

namespace App\Domains\Treasury\Models;

use App\Domains\Accounting\Models\Account;
use App\Domains\Treasury\Policies\BankAccountPolicy;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cuenta bancaria: metadatos sobre una cuenta contable.
 *
 * No tiene saldo propio a propósito. El saldo se pregunta al libro a través de
 * `BankAccountService::bookBalance()`; un saldo guardado aquí sería un segundo
 * número que mantener de acuerdo con el primero.
 *
 * @property string $bank_name
 * @property string $number
 */
#[UsePolicy(BankAccountPolicy::class)]
#[Fillable([
    'account_id', 'bank_name', 'number', 'alias', 'type',
    'currency_code', 'next_check_number', 'is_active', 'notes',
])]
class BankAccount extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'next_check_number' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return HasMany<Check, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }

    /** @return HasMany<BankReconciliation, $this> */
    public function reconciliations(): HasMany
    {
        return $this->hasMany(BankReconciliation::class);
    }

    public function label(): string
    {
        return $this->alias ?: $this->bank_name.' '.$this->number;
    }

    /**
     * Si la cuenta gira cheques. Una cuenta de ahorro normalmente no.
     */
    public function issuesChecks(): bool
    {
        return $this->next_check_number !== null;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
