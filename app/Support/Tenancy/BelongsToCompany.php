<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Domains\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Aísla un modelo por empresa.
 *
 * Cubre las tres operaciones donde se filtran datos entre empresas:
 *  - lectura  → global scope
 *  - creación → company_id se asigna desde el contexto, nunca desde el request
 *  - traslado → prohibido mover un registro de una empresa a otra
 *
 * @property int $company_id
 *
 * @method static Builder<static> query()
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('company_id') === null) {
                $model->setAttribute('company_id', app(CompanyContext::class)->idOrFail());
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('company_id')) {
                $original = $model->getOriginal('company_id');
                $new = $model->getAttribute('company_id');

                throw new RuntimeException(sprintf(
                    'No se puede mover un registro [%s#%s] de la empresa %s a la %s. '
                    .'Los documentos pertenecen permanentemente a su empresa.',
                    $model::class, (string) $model->getKey(), (string) $original, (string) $new
                ));
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Consulta sin el filtro de empresa. Explícita a propósito: cada uso debe
     * poder justificarse en una revisión de código.
     *
     * @return Builder<static>
     */
    public static function acrossCompanies(): Builder
    {
        return static::query()->withoutGlobalScope(CompanyScope::class);
    }
}
