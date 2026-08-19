<?php

declare(strict_types=1);

namespace App\Domains\Receivables\Models;

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Partners\Models\Customer;
use App\Domains\Receivables\Policies\ReceiptPolicy;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Tenancy\Models\Branch;
use App\Models\User;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Recibo de cobro.
 *
 * @property string $number
 * @property string $amount
 * @property PaymentMethod $payment_method
 */
#[UseFactory(ReceiptFactory::class)]
#[UsePolicy(ReceiptPolicy::class)]
#[Fillable([
    'branch_id', 'customer_id', 'date', 'payment_method',
    'reference', 'deposit_account_id', 'notes',
])]
class Receipt extends Model
{
    /** @use HasFactory<ReceiptFactory> */
    use BelongsToCompany, HasFactory;

    public const SOURCE_TYPE = 'receipt';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:4',
            'voided_at' => 'datetime',
        ];
    }

    /** @return HasMany<ReceiptApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(ReceiptApplication::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function depositAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'deposit_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function journalEntry(): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $this->id)
            ->where('status', '!=', JournalEntryStatus::Voided)
            ->first();
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }

    /** @param  Builder<self>  $query */
    public function scopeIssued(Builder $query): void
    {
        $query->where('status', 'issued');
    }
}
