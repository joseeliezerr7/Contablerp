<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Policies;

use App\Domains\Accounting\Models\Account;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;

/**
 * Dos condiciones, ambas obligatorias: el permiso del rol y la pertenencia del
 * registro a la empresa activa. El permiso sin la comprobación de empresa
 * dejaría que un contador con permiso editara el catálogo de otra empresa.
 */
class AccountPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->has() && $user->can('accounting.accounts.view');
    }

    public function view(User $user, Account $account): bool
    {
        return $this->inActiveCompany($account) && $user->can('accounting.accounts.view');
    }

    public function create(User $user): bool
    {
        return $this->context->has() && $user->can('accounting.accounts.create');
    }

    public function update(User $user, Account $account): bool
    {
        return $this->inActiveCompany($account) && $user->can('accounting.accounts.update');
    }

    public function delete(User $user, Account $account): bool
    {
        // Las cuentas del sistema solo se pueden desactivar; el servicio vuelve a
        // comprobarlo, pero el botón tampoco debe aparecer.
        return $this->inActiveCompany($account)
            && ! $account->is_system
            && $user->can('accounting.accounts.delete');
    }

    private function inActiveCompany(Account $account): bool
    {
        return $this->context->has() && $account->company_id === $this->context->id();
    }
}
