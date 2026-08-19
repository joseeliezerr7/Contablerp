<?php

declare(strict_types=1);

namespace App\Domains\Fiscal\Models;

use App\Domains\Fiscal\Enums\AuthorizationStatus;
use App\Domains\Fiscal\Enums\FiscalDocumentType;
use App\Domains\Fiscal\Policies\FiscalAuthorizationPolicy;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Autorización de impresión emitida por el SAR.
 *
 * @property AuthorizationStatus $status
 * @property FiscalDocumentType $document_type
 * @property int $range_from
 * @property int $range_to
 * @property int $next_number
 */
#[UsePolicy(FiscalAuthorizationPolicy::class)]
class FiscalAuthorization extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'document_type' => FiscalDocumentType::class,
            'status' => AuthorizationStatus::class,
            'range_from' => 'integer',
            'range_to' => 'integer',
            'next_number' => 'integer',
            'issued_on' => 'date',
            'limit_date' => 'date',
        ];
    }

    /** @return BelongsTo<FiscalPoint, $this> */
    public function point(): BelongsTo
    {
        return $this->belongsTo(FiscalPoint::class, 'fiscal_point_id');
    }

    /**
     * Cuántos correlativos quedan sin usar.
     */
    public function remaining(): int
    {
        return max(0, $this->range_to - $this->next_number + 1);
    }

    public function total(): int
    {
        return $this->range_to - $this->range_from + 1;
    }

    public function used(): int
    {
        return $this->total() - $this->remaining();
    }

    /**
     * Porcentaje consumido, para avisar antes de que se acabe.
     */
    public function usedPercent(): int
    {
        return $this->total() === 0 ? 100 : (int) round($this->used() / $this->total() * 100);
    }

    public function hasRangeLeft(): bool
    {
        return $this->next_number <= $this->range_to;
    }

    public function isExpiredOn(DateTimeInterface|string|null $date = null): bool
    {
        return CarbonImmutable::parse($date ?? now())->startOfDay()
            ->greaterThan(CarbonImmutable::parse($this->limit_date)->startOfDay());
    }

    /**
     * Días que faltan para la fecha límite. Negativo si ya pasó.
     */
    public function daysToLimit(DateTimeInterface|string|null $asOf = null): int
    {
        $now = CarbonImmutable::parse($asOf ?? now())->startOfDay();

        return (int) $now->diffInDays(CarbonImmutable::parse($this->limit_date)->startOfDay(), false);
    }

    /**
     * Si hoy se puede emitir con esta autorización.
     */
    public function canEmitOn(DateTimeInterface|string|null $date = null): bool
    {
        return $this->status->canEmit()
            && $this->hasRangeLeft()
            && ! $this->isExpiredOn($date);
    }

    /**
     * Número fiscal completo para un correlativo dado: `000-001-01-00000042`.
     */
    public function formatNumber(int $sequence): string
    {
        return implode('-', [
            $this->point->establishment_code,
            $this->point->emission_point_code,
            $this->document_type_code,
            str_pad((string) $sequence, 8, '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * El rango tal como se imprime en el documento.
     */
    public function rangeLabel(): string
    {
        return $this->formatNumber($this->range_from).' al '.$this->formatNumber($this->range_to);
    }

    public function label(): string
    {
        return $this->document_type->label().' '.$this->cai;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', AuthorizationStatus::Active);
    }
}
