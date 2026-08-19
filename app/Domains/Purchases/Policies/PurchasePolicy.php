<?php

declare(strict_types=1);

namespace App\Domains\Purchases\Policies;

use App\Domains\Purchases\Models\Purchase;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class PurchasePolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'purchases.view');
    }

    public function view(User $user, Purchase $purchase): bool
    {
        return $this->allows($user, 'purchases.view', $purchase);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'purchases.create');
    }

    public function update(User $user, Purchase $purchase): bool
    {
        return $purchase->isDraft() && $this->allows($user, 'purchases.update', $purchase);
    }

    public function delete(User $user, Purchase $purchase): bool
    {
        return $purchase->isDraft() && $this->allows($user, 'purchases.delete', $purchase);
    }

    public function receive(User $user, Purchase $purchase): bool
    {
        return $purchase->isDraft() && $this->allows($user, 'purchases.receive', $purchase);
    }

    public function void(User $user, Purchase $purchase): bool
    {
        return $purchase->isReceived() && $this->allows($user, 'purchases.void', $purchase);
    }
}
