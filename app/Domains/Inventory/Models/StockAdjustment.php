<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Inventory\Enums\AdjustmentReason;
use App\Domains\Inventory\Enums\StockDocumentStatus;
use App\Domains\Inventory\Policies\StockAdjustmentPolicy;
use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Warehouse;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $number
 * @property StockDocumentStatus $status
 * @property AdjustmentReason $reason
 * @property string $total_value
 */
#[UsePolicy(StockAdjustmentPolicy::class)]
#[Fillable(['branch_id', 'warehouse_id', 'date', 'reason', 'adjustment_account_id', 'notes'])]
class StockAdjustment extends Model
{
    use BelongsToCompany;

    public const SOURCE_TYPE = 'stock_adjustment';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'reason' => AdjustmentReason::class,
            'status' => StockDocumentStatus::class,
            'total_value' => 'decimal:4',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** @return HasMany<StockAdjustmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class)->orderBy('line_number');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function adjustmentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'adjustment_account_id');
    }

    public function journalEntry(): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $this->id)
            ->where('status', '!=', JournalEntryStatus::Voided)
            ->first();
    }

    public function valueAmount(): Money
    {
        return Money::of($this->total_value);
    }

    public function isDraft(): bool
    {
        return $this->status === StockDocumentStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === StockDocumentStatus::Posted;
    }

    public function isVoided(): bool
    {
        return $this->status === StockDocumentStatus::Voided;
    }

    public function label(): string
    {
        return $this->number ?? 'Borrador #'.$this->id;
    }
}
