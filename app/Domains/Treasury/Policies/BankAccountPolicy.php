<?php

declare(strict_types=1);

namespace App\Domains\Treasury\Policies;

use App\Domains\Treasury\Models\BankAccount;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class BankAccountPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'treasury.banks.view');
    }

    public function view(User $user, BankAccount $account): bool
    {
        return $this->allows($user, 'treasury.banks.view', $account);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'treasury.banks.manage');
    }

    public function update(User $user, BankAccount $account): bool
    {
        return $this->allows($user, 'treasury.banks.manage', $account);
    }

    public function delete(User $user, BankAccount $account): bool
    {
        return $this->allows($user, 'treasury.banks.manage', $account);
    }
}
