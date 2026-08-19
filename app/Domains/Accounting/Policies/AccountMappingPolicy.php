<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Policies;

use App\Domains\Accounting\Models\AccountMapping;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

/**
 * El puente entre los módulos y el plan de cuentas.
 *
 * Cambiar una de estas asignaciones redirige **todo lo que se contabilice de
 * aquí en adelante**, así que es del contador, no de quien opera. No se crean
 * ni se borran: las claves las define el enum y siempre están todas; lo único
 * que se hace es asignarles una cuenta.
 */
class AccountMappingPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'accounting.mappings.view');
    }

    public function view(User $user, AccountMapping $mapping): bool
    {
        return $this->allows($user, 'accounting.mappings.view', $mapping);
    }

    public function update(User $user, ?AccountMapping $mapping = null): bool
    {
        return $this->allows($user, 'accounting.mappings.update', $mapping);
    }
}
