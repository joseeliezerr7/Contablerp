<?php

declare(strict_types=1);

namespace App\Domains\Assets\Models;

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Assets\Policies\DepreciationRunPolicy;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $total
 * @property string $status
 */
#[UsePolicy(DepreciationRunPolicy::class)]
class DepreciationRun extends Model
{
    use BelongsToCompany;

    public const SOURCE_TYPE = 'depreciation_run';

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'posted_on' => 'date',
            'total' => 'decimal:4',
            'asset_count' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    /** @return HasMany<DepreciationRunLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DepreciationRunLine::class);
    }

    public function journalEntry(): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $this->id)
            ->where('status', '!=', JournalEntryStatus::Voided)
            ->first();
    }

    public function totalAmount(): Money
    {
        return Money::of($this->total);
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function label(): string
    {
        return 'Depreciación de '.$this->period_month->translatedFormat('F \d\e Y');
    }

    public function badgeClasses(): string
    {
        return $this->isVoided()
            ? 'bg-red-50 text-red-700'
            : 'bg-emerald-50 text-emerald-700';
    }
}
