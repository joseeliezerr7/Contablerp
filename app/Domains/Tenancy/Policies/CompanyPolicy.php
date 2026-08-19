<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Policies;

use App\Domains\Tenancy\Models\Company;
use App\Models\User;

/**
 * Capa 4 del aislamiento. Company no tiene scope global (es la empresa), así
 * que su autorización se resuelve exclusivamente por pertenencia.
 *
 * Los permisos por rol se añaden en la Fase 1, cuando exista el RBAC.
 */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Company $company): bool
    {
        return $user->belongsToCompany($company->id);
    }

    /**
     * Crear empresas queda dentro del propio tenant del usuario.
     */
    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, Company $company): bool
    {
        return $user->belongsToCompany($company->id);
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->belongsToCompany($company->id);
    }

    /**
     * Activar una empresa desde el selector.
     */
    public function switchTo(User $user, Company $company): bool
    {
        return $company->is_active && $user->belongsToCompany($company->id);
    }
}
