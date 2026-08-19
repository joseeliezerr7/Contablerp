<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Policies;

use App\Domains\Inventory\Models\StockTransfer;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class StockTransferPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'inventory.transfers.view');
    }

    public function view(User $user, StockTransfer $transfer): bool
    {
        return $this->allows($user, 'inventory.transfers.view', $transfer);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'inventory.transfers.create');
    }

    public function update(User $user, StockTransfer $transfer): bool
    {
        return $transfer->isDraft() && $this->allows($user, 'inventory.transfers.create', $transfer);
    }

    public function delete(User $user, StockTransfer $transfer): bool
    {
        return $transfer->isDraft() && $this->allows($user, 'inventory.transfers.create', $transfer);
    }

    public function post(User $user, StockTransfer $transfer): bool
    {
        return $transfer->isDraft() && $this->allows($user, 'inventory.transfers.post', $transfer);
    }

    public function void(User $user, StockTransfer $transfer): bool
    {
        return $transfer->isPosted() && $this->allows($user, 'inventory.transfers.void', $transfer);
    }
}
