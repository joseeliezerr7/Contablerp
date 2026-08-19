<?php

declare(strict_types=1);

namespace App\Domains\Partners\Policies;

use App\Domains\Partners\Models\Supplier;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class SupplierPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'suppliers.view');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->allows($user, 'suppliers.view', $supplier);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'suppliers.create');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->allows($user, 'suppliers.update', $supplier);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->allows($user, 'suppliers.delete', $supplier);
    }
}
