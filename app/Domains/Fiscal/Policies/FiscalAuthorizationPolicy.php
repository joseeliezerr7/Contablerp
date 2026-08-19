<?php

declare(strict_types=1);

namespace App\Domains\Fiscal\Policies;

use App\Domains\Fiscal\Models\FiscalAuthorization;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class FiscalAuthorizationPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'fiscal.authorizations.view');
    }

    public function view(User $user, FiscalAuthorization $authorization): bool
    {
        return $this->allows($user, 'fiscal.authorizations.view', $authorization);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'fiscal.authorizations.manage');
    }

    /**
     * Una autorización que ya emitió documentos **no se edita**: cambiarle el
     * CAI o el rango contradiría el papel que el cliente ya tiene en la mano.
     * Solo se corrige mientras no ha numerado nada.
     */
    public function update(User $user, FiscalAuthorization $authorization): bool
    {
        return $this->allows($user, 'fiscal.authorizations.manage', $authorization)
            && $authorization->used() === 0;
    }

    public function delete(User $user, FiscalAuthorization $authorization): bool
    {
        return $this->update($user, $authorization);
    }

    /**
     * Darla por terminada antes de agotar el rango: pasa cuando el SAR emite
     * una nueva y hay que dejar de usar la anterior.
     */
    public function replace(User $user, FiscalAuthorization $authorization): bool
    {
        return $this->allows($user, 'fiscal.authorizations.manage', $authorization)
            && $authorization->status->canEmit();
    }
}
