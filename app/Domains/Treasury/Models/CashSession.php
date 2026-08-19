<?php

declare(strict_types=1);

namespace App\Domains\Treasury\Models;

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Tenancy\Models\Branch;
use App\Domains\Treasury\Enums\CashSessionStatus;
use App\Domains\Treasury\Policies\CashSessionPolicy;
use App\Models\User;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CashSessionStatus $status
 * @property string $opening_float
 * @property string|null $difference
 */
#[UsePolicy(CashSessionPolicy::class)]
#[Fillable(['branch_id', 'account_id', 'opening_float', 'notes'])]
class CashSession extends Model
{
    use BelongsToCompany;

    public const SOURCE_TYPE = 'cash_session';

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_float' => 'decimal:4',
            'counted_amount' => 'decimal:4',
            'expected_amount' => 'decimal:4',
            'difference' => 'decimal:4',
            'status' => CashSessionStatus::class,
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function journalEntry(): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $this->id)
            ->where('status', '!=', JournalEntryStatus::Voided)
            ->first();
    }

    public function openingFloat(): Money
    {
        return Money::of($this->opening_float);
    }

    public function countedAmount(): Money
    {
        return Money::of($this->counted_amount ?? '0');
    }

    public function expectedAmount(): Money
    {
        return Money::of($this->expected_amount ?? '0');
    }

    public function differenceAmount(): Money
    {
        return Money::of($this->difference ?? '0');
    }

    public function isOpen(): bool
    {
        return $this->status === CashSessionStatus::Open;
    }

    public function isClosed(): bool
    {
        return $this->status === CashSessionStatus::Closed;
    }

    /**
     * Un sobrante es dinero que apareció sin documento; un faltante, dinero que
     * se fue sin él. Los dos preocupan, pero no igual.
     */
    public function isShort(): bool
    {
        return $this->differenceAmount()->isNegative();
    }

    public function label(): string
    {
        return $this->number ?? 'Sesión #'.$this->id;
    }
}
