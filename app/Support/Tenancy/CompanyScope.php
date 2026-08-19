<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtra toda consulta por la empresa activa.
 *
 * Sin empresa activa el scope NO devuelve resultados sin filtrar: lanza. Un
 * scope que se desactiva solo cuando falta contexto es exactamente el bug que
 * filtra datos entre empresas en consolas, jobs y peticiones mal enrutadas.
 */
final class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CompanyContext::class);

        if ($context->isUnscoped()) {
            return;
        }

        if (! $context->has()) {
            throw MissingCompanyContextException::forModel($model);
        }

        $builder->where($model->qualifyColumn('company_id'), $context->id());
    }
}
