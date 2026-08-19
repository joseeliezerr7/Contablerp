<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Models;

use App\Domains\Accounting\Enums\PeriodStatus;
use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Database\Factories\AccountingPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $fiscal_year_id
 * @property int $number
 * @property string $name
 * @property CarbonInterface $starts_on
 * @property CarbonInterface $ends_on
 * @property PeriodStatus $status
 */
#[UseFactory(AccountingPeriodFactory::class)]
#[Fillable(['fiscal_year_id', 'number', 'name', 'starts_on', 'ends_on', 'status'])]
class AccountingPeriod extends Model
{
    /** @use HasFactory<AccountingPeriodFactory> */
    use BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => PeriodStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FiscalYear, $this> */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /** @return HasMany<JournalEntry, $this> */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function acceptsPostings(): bool
    {
        return $this->status->acceptsPostings();
    }

    public function label(): string
    {
        return "{$this->name} ({$this->starts_on->format('d/m/Y')} – {$this->ends_on->format('d/m/Y')})";
    }

    /** @param  Builder<self>  $query */
    public function scopeContaining(Builder $query, \DateTimeInterface $date): void
    {
        $formatted = $date->format('Y-m-d');

        $query->where('starts_on', '<=', $formatted)
            ->where('ends_on', '>=', $formatted);
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', PeriodStatus::Open);
    }
}
