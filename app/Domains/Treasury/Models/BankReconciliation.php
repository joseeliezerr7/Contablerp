<?php

declare(strict_types=1);

namespace App\Domains\Treasury\Models;

use App\Domains\Treasury\Enums\ReconciliationStatus;
use App\Domains\Treasury\Policies\BankReconciliationPolicy;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ReconciliationStatus $status
 * @property string $statement_balance
 * @property string $difference
 */
#[UsePolicy(BankReconciliationPolicy::class)]
#[Fillable(['bank_account_id', 'cutoff_date', 'statement_balance', 'notes'])]
class BankReconciliation extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date',
            'statement_balance' => 'decimal:4',
            'book_balance' => 'decimal:4',
            'deposits_in_transit' => 'decimal:4',
            'outstanding_checks' => 'decimal:4',
            'difference' => 'decimal:4',
            'status' => ReconciliationStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return HasMany<BankReconciliationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BankReconciliationLine::class);
    }

    public function statementBalance(): Money
    {
        return Money::of($this->statement_balance);
    }

    public function bookBalance(): Money
    {
        return Money::of($this->book_balance);
    }

    public function depositsInTransit(): Money
    {
        return Money::of($this->deposits_in_transit);
    }

    public function outstandingChecks(): Money
    {
        return Money::of($this->outstanding_checks);
    }

    public function differenceAmount(): Money
    {
        return Money::of($this->difference);
    }

    public function isBalanced(): bool
    {
        return $this->differenceAmount()->isZero();
    }

    public function isDraft(): bool
    {
        return $this->status === ReconciliationStatus::Draft;
    }

    public function isClosed(): bool
    {
        return $this->status === ReconciliationStatus::Closed;
    }

    public function label(): string
    {
        return 'Conciliación al '.$this->cutoff_date->format('d/m/Y');
    }
}
