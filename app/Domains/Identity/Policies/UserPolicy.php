<?php

declare(strict_types=1);

namespace App\Domains\Identity\Policies;

use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

/**
 * `User` no lleva `BelongsToCompany` —pertenece al tenant, no a una empresa—,
 * así que la pertenencia se comprueba contra la tabla pivote.
 */
class UserPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'users.view');
    }

    public function view(User $user, User $target): bool
    {
        return $this->allows($user, 'users.view') && $this->inCompany($target);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'users.create');
    }

    public function update(User $user, User $target): bool
    {
        return $this->allows($user, 'users.update') && $this->inCompany($target);
    }

    /**
     * «Eliminar» aquí significa quitarle el acceso a esta empresa. El registro
     * nunca se borra: los documentos lo referencian.
     */
    public function revokeAccess(User $user, User $target): bool
    {
        return $this->allows($user, 'users.delete')
            && $this->inCompany($target)
            && $user->id !== $target->id;
    }

    /**
     * Generar una contraseña temporal es dar acceso: va con el permiso de
     * editar, no con el de ver.
     */
    public function resetPassword(User $user, User $target): bool
    {
        return $this->allows($user, 'users.update') && $this->inCompany($target);
    }

    private function inCompany(User $target): bool
    {
        return $this->context->has()
            && $target->companies()->whereKey($this->context->id())->exists();
    }
}
