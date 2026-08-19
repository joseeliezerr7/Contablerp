<?php

declare(strict_types=1);

namespace App\Domains\Partners\Policies;

use App\Domains\Partners\Models\Customer;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class CustomerPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->allows($user, 'customers.view', $customer);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'customers.create');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->allows($user, 'customers.update', $customer);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->allows($user, 'customers.delete', $customer);
    }
}
