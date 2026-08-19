<?php

declare(strict_types=1);

namespace App\Domains\Assets\Models;

use App\Domains\Assets\Enums\FixedAssetStatus;
use App\Domains\Assets\Policies\FixedAssetPolicy;
use App\Domains\Tenancy\Models\Branch;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $cost
 * @property string $salvage_value
 * @property string $accumulated_depreciation
 * @property string $book_value
 * @property FixedAssetStatus $status
 */
#[UsePolicy(FixedAssetPolicy::class)]
#[Fillable([
    'branch_id', 'fixed_asset_category_id', 'code', 'name', 'description',
    'serial_number', 'location', 'acquired_on', 'cost', 'salvage_value',
    'useful_life_months',
])]
class FixedAsset extends Model
{
    use BelongsToCompany;

    public const SOURCE_TYPE = 'fixed_asset';

    protected function casts(): array
    {
        return [
            'acquired_on' => 'date',
            'disposed_on' => 'date',
            'depreciated_through' => 'date',
            'cost' => 'decimal:4',
            'salvage_value' => 'decimal:4',
            'accumulated_depreciation' => 'decimal:4',
            'book_value' => 'decimal:4',
            'disposal_amount' => 'decimal:4',
            'useful_life_months' => 'integer',
            'status' => FixedAssetStatus::class,
        ];
    }

    /** @return BelongsTo<FixedAssetCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FixedAssetCategory::class, 'fixed_asset_category_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<DepreciationRunLine, $this> */
    public function depreciationLines(): HasMany
    {
        return $this->hasMany(DepreciationRunLine::class);
    }

    public function costAmount(): Money
    {
        return Money::of($this->cost);
    }

    public function salvageValue(): Money
    {
        return Money::of($this->salvage_value);
    }

    public function accumulated(): Money
    {
        return Money::of($this->accumulated_depreciation);
    }

    public function bookValue(): Money
    {
        return Money::of($this->book_value);
    }

    /**
     * Lo que todavía queda por depreciar: valor en libros menos residual.
     *
     * Es el techo de la corrida mensual. Cuando llega a cero, el activo pasa a
     * «totalmente depreciado» y deja de generar gasto.
     */
    public function remainingDepreciable(): Money
    {
        $remaining = $this->bookValue()->minus($this->salvageValue());

        return $remaining->isPositive() ? $remaining : Money::zero();
    }

    /**
     * Cuota mensual en línea recta: base depreciable entre vida útil.
     */
    public function monthlyQuota(): Money
    {
        if ($this->useful_life_months <= 0) {
            return Money::zero();
        }

        return $this->costAmount()
            ->minus($this->salvageValue())
            ->dividedBy($this->useful_life_months);
    }

    /**
     * Si le toca depreciación en el mes indicado.
     *
     * La depreciación arranca **el mes siguiente al de la compra**. Un activo
     * comprado el día 28 no debe cargar el mes entero, y prorratear por días
     * complicaría el cálculo para ganar unos lempiras; empezar al mes siguiente
     * es la convención simple y la que se explica sin esfuerzo a un auditor.
     */
    public function depreciatesIn(DateTimeInterface|string $month): bool
    {
        if (! $this->status->depreciates()) {
            return false;
        }

        $target = CarbonImmutable::parse($month)->startOfMonth();
        $starts = CarbonImmutable::parse($this->acquired_on)->startOfMonth()->addMonth();

        if ($target->lt($starts)) {
            return false;
        }

        if ($this->depreciated_through !== null
            && $target->lte(CarbonImmutable::parse($this->depreciated_through)->startOfMonth())) {
            return false;
        }

        return $this->remainingDepreciable()->isPositive();
    }

    public function isDisposed(): bool
    {
        return $this->status === FixedAssetStatus::Disposed;
    }

    public function label(): string
    {
        return $this->code.' — '.$this->name;
    }

    /** @param  Builder<self>  $query */
    public function scopeDepreciable(Builder $query): void
    {
        $query->where('status', FixedAssetStatus::Active);
    }

    /** @param  Builder<self>  $query */
    public function scopeSearch(Builder $query, string $term): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('code', 'like', '%'.$term.'%')
            ->orWhere('name', 'like', '%'.$term.'%')
            ->orWhere('serial_number', 'like', '%'.$term.'%'));
    }
}
