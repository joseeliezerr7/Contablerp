<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Se lanza cuando se consulta un modelo aislado por empresa sin que haya una
 * empresa activa. Fallar es intencional: devolver resultados sin filtrar
 * expondría datos de todas las empresas.
 */
final class MissingCompanyContextException extends RuntimeException
{
    public static function forModel(Model $model): self
    {
        $class = $model::class;

        return new self(
            "Se intentó consultar [{$class}] sin empresa activa en el contexto. "
            .'Activa una empresa con CompanyContext::set()/runFor(), o envuelve la '
            .'consulta en CompanyContext::unscoped() si realmente debe cruzar empresas.'
        );
    }
}
