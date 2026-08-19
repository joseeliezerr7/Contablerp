<?php

declare(strict_types=1);

namespace App\Domains\Identity\Policies;

use App\Domains\Identity\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

/**
 * La bitácora se lee, nunca se escribe ni se corrige desde la aplicación: un
 * registro que se puede editar no sirve para auditar. Por eso aquí solo hay
 * permisos de lectura.
 *
 * `AuditLog` no lleva el scope global de empresa —un auditor debe poder ver
 * eventos de una empresa ya eliminada—, así que la pertenencia se comprueba a
 * mano en cada consulta y también aquí.
 */
class AuditLogPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'audit.view');
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $this->allows($user, 'audit.view', $log);
    }
}
