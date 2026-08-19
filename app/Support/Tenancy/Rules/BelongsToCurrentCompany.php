<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Rules;

use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Valida que una llave foránea enviada por el cliente pertenezca a la empresa
 * activa.
 *
 * Sin esta regla, `exists:branches,id` acepta el id de una sucursal de otra
 * empresa: el scope global protege las lecturas, no los identificadores que
 * llegan en el request.
 */
final class BelongsToCurrentCompany implements ValidationRule
{
    /** @var array<string, bool> */
    private static array $softDeleteCache = [];

    public function __construct(
        private readonly string $table,
        private readonly string $column = 'id',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $companyId = app(CompanyContext::class)->id();

        if ($companyId === null) {
            $fail('No hay una empresa activa para validar :attribute.');

            return;
        }

        $query = DB::table($this->table)
            ->where($this->column, $value)
            ->where('company_id', $companyId);

        if ($this->tableUsesSoftDeletes()) {
            $query->whereNull('deleted_at');
        }

        if (! $query->exists()) {
            $fail('El valor seleccionado en :attribute no pertenece a la empresa activa.');
        }
    }

    private function tableUsesSoftDeletes(): bool
    {
        return self::$softDeleteCache[$this->table] ??= Schema::hasColumn($this->table, 'deleted_at');
    }
}
