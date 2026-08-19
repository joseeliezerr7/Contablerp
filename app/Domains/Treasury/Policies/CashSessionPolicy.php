<?php

declare(strict_types=1);

namespace App\Domains\Treasury\Policies;

use App\Domains\Treasury\Models\CashSession;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class CashSessionPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'treasury.cash.view');
    }

    public function view(User $user, CashSession $session): bool
    {
        return $this->allows($user, 'treasury.cash.view', $session);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'treasury.cash.operate');
    }

    /**
     * Cerrar la caja lo hace el propio cajero: es él quien cuenta el dinero.
     */
    public function close(User $user, CashSession $session): bool
    {
        return $session->isOpen() && $this->allows($user, 'treasury.cash.operate', $session);
    }
}
