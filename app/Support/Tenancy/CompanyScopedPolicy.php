<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Base de las policies de modelos aislados por empresa.
 *
 * Toda autorización exige dos condiciones a la vez: el permiso del rol y que el
 * registro pertenezca a la empresa activa. El permiso por sí solo dejaría que
 * un vendedor con permiso de facturar tocara documentos de otra empresa si un
 * id llegara por otra vía.
 */
abstract class CompanyScopedPolicy
{
    public function __construct(protected readonly CompanyContext $context) {}

    /**
     * @param  Model|null  $model  Si se pasa, además se comprueba su empresa.
     */
    protected function allows(User $user, string $permission, ?Model $model = null): bool
    {
        if (! $this->context->has()) {
            return false;
        }

        if ($model !== null && ! $this->inActiveCompany($model)) {
            return false;
        }

        return $user->can($permission);
    }

    protected function inActiveCompany(Model $model): bool
    {
        return $this->context->has()
            && $model->getAttribute('company_id') === $this->context->id();
    }
}
