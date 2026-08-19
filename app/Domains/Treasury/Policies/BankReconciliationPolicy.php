<?php

declare(strict_types=1);

namespace App\Domains\Treasury\Policies;

use App\Domains\Treasury\Models\BankReconciliation;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class BankReconciliationPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'treasury.reconciliation.view');
    }

    public function view(User $user, BankReconciliation $reconciliation): bool
    {
        return $this->allows($user, 'treasury.reconciliation.view', $reconciliation);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'treasury.reconciliation.manage');
    }

    public function update(User $user, BankReconciliation $reconciliation): bool
    {
        return $reconciliation->isDraft()
            && $this->allows($user, 'treasury.reconciliation.manage', $reconciliation);
    }

    public function delete(User $user, BankReconciliation $reconciliation): bool
    {
        return $reconciliation->isDraft()
            && $this->allows($user, 'treasury.reconciliation.manage', $reconciliation);
    }

    /**
     * Cerrar una conciliación es un acto de aprobación: quien la arma no tiene
     * por qué ser quien la da por buena.
     */
    public function close(User $user, BankReconciliation $reconciliation): bool
    {
        return $reconciliation->isDraft()
            && $this->allows($user, 'treasury.reconciliation.close', $reconciliation);
    }

    public function reopen(User $user, BankReconciliation $reconciliation): bool
    {
        return $reconciliation->isClosed()
            && $this->allows($user, 'treasury.reconciliation.close', $reconciliation);
    }
}
