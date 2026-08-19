<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Models;

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Enums\JournalEntryType;
use App\Domains\Accounting\Policies\JournalEntryPolicy;
use App\Domains\Tenancy\Models\Branch;
use App\Models\User;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Database\Factories\JournalEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Partida contable.
 *
 * @property int $id
 * @property int $company_id
 * @property int $accounting_period_id
 * @property string $number
 * @property CarbonInterface $date
 * @property JournalEntryType $type
 * @property string $concept
 * @property JournalEntryStatus $status
 * @property string $total_debit
 * @property string $total_credit
 */
#[UseFactory(JournalEntryFactory::class)]
#[UsePolicy(JournalEntryPolicy::class)]
#[Fillable(['branch_id', 'date', 'type', 'concept', 'reference'])]
class JournalEntry extends Model
{
    /** @use HasFactory<JournalEntryFactory> */
    use BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => JournalEntryType::class,
            'status' => JournalEntryStatus::class,
            // Los importes se leen como string y se operan con Money/bcmath.
            'total_debit' => 'decimal:4',
            'total_credit' => 'decimal:4',
            'exchange_rate' => 'decimal:8',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** @return HasMany<JournalEntryLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('line_number');
    }

    /** @return BelongsTo<AccountingPeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<self, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /** @return HasMany<self, $this> */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function totalDebit(): Money
    {
        return Money::of($this->total_debit);
    }

    public function totalCredit(): Money
    {
        return Money::of($this->total_credit);
    }

    public function difference(): Money
    {
        return $this->totalDebit()->minus($this->totalCredit());
    }

    public function isBalanced(): bool
    {
        return $this->difference()->isZero();
    }

    public function isPosted(): bool
    {
        return $this->status === JournalEntryStatus::Posted;
    }

    public function isDraft(): bool
    {
        return $this->status === JournalEntryStatus::Draft;
    }

    public function isVoided(): bool
    {
        return $this->status === JournalEntryStatus::Voided;
    }

    /**
     * Proviene de un documento operativo (venta, compra, pago), no de una
     * captura manual en el libro diario.
     */
    public function isAutomatic(): bool
    {
        return $this->source_type !== null;
    }

    /** @param  Builder<self>  $query */
    public function scopePosted(Builder $query): void
    {
        $query->where('status', JournalEntryStatus::Posted);
    }

    /** @param  Builder<self>  $query */
    public function scopeBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): void
    {
        $query->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')]);
    }
}
