<?php

declare(strict_types=1);

namespace App\Domains\Treasury\Policies;

use App\Domains\Treasury\Models\Check;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class CheckPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'treasury.checks.view');
    }

    public function view(User $user, Check $check): bool
    {
        return $this->allows($user, 'treasury.checks.view', $check);
    }

    /**
     * Marcar entregado o cobrado es seguimiento del documento, no contabilidad:
     * ninguna de las dos cosas mueve el libro.
     */
    public function update(User $user, Check $check): bool
    {
        return ! $check->isVoided() && $this->allows($user, 'treasury.checks.manage', $check);
    }

    /**
     * Anular un cheque sí toca el libro —hay que revertir el pago—, así que va
     * con el permiso de anular pagos.
     */
    public function void(User $user, Check $check): bool
    {
        return ! $check->isVoided() && $this->allows($user, 'payments.void', $check);
    }
}
