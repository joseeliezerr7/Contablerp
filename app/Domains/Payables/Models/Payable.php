<?php

declare(strict_types=1);

namespace App\Domains\Payables\Models;

use App\Domains\Partners\Models\Supplier;
use App\Domains\Payables\Enums\PayableStatus;
use App\Domains\Purchases\Models\Purchase;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $original_amount
 * @property string $paid_amount
 * @property string $balance
 * @property PayableStatus $status
 */
#[Fillable(['supplier_id', 'purchase_id', 'document_number', 'date', 'due_date', 'original_amount'])]
class Payable extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'due_date' => 'date',
            'original_amount' => 'decimal:4',
            'paid_amount' => 'decimal:4',
            'balance' => 'decimal:4',
            'status' => PayableStatus::class,
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return HasMany<PaymentApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(PaymentApplication::class);
    }

    public function originalAmount(): Money
    {
        return Money::of($this->original_amount);
    }

    public function paidAmount(): Money
    {
        return Money::of($this->paid_amount);
    }

    public function balanceAmount(): Money
    {
        return Money::of($this->balance);
    }

    public function isOutstanding(): bool
    {
        return $this->status === PayableStatus::Open;
    }

    public function isOverdue(?DateTimeInterface $asOf = null): bool
    {
        return $this->isOutstanding() && $this->due_date->lt($asOf ?? now());
    }

    public function daysOverdue(?DateTimeInterface $asOf = null): int
    {
        $asOf = $asOf === null ? now()->startOfDay() : CarbonImmutable::parse($asOf)->startOfDay();

        return $this->due_date->gte($asOf) ? 0 : (int) $this->due_date->diffInDays($asOf);
    }

    /** @param  Builder<self>  $query */
    public function scopeOutstanding(Builder $query): void
    {
        $query->where('status', PayableStatus::Open)->where('balance', '>', 0);
    }

    /** @param  Builder<self>  $query */
    public function scopeOverdue(Builder $query, ?DateTimeInterface $asOf = null): void
    {
        $query->outstanding()->where('due_date', '<', ($asOf ?? now())->format('Y-m-d'));
    }
}
