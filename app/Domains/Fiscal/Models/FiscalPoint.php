<?php

declare(strict_types=1);

namespace App\Domains\Fiscal\Models;

use App\Domains\Fiscal\Enums\AuthorizationStatus;
use App\Domains\Fiscal\Enums\FiscalDocumentType;
use App\Domains\Fiscal\Policies\FiscalPointPolicy;
use App\Domains\Tenancy\Models\Branch;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Punto de emisión: la caja o el mostrador desde el que se factura.
 *
 * @property string $establishment_code
 * @property string $emission_point_code
 */
#[UsePolicy(FiscalPointPolicy::class)]
#[Fillable(['branch_id', 'establishment_code', 'emission_point_code', 'name', 'is_active'])]
class FiscalPoint extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<FiscalAuthorization, $this> */
    public function authorizations(): HasMany
    {
        return $this->hasMany(FiscalAuthorization::class);
    }

    /**
     * Autorización vigente para un tipo de documento, si la hay.
     */
    public function activeAuthorization(FiscalDocumentType $type): ?FiscalAuthorization
    {
        return $this->authorizations()
            ->where('document_type', $type)
            ->where('status', AuthorizationStatus::Active)
            ->first();
    }

    /**
     * `000-001`: las dos primeras partes del número fiscal.
     */
    public function prefix(): string
    {
        return $this->establishment_code.'-'.$this->emission_point_code;
    }

    public function label(): string
    {
        return $this->prefix().' — '.$this->name;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
