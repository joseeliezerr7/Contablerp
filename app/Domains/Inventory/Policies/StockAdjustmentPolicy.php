<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Policies;

use App\Domains\Inventory\Models\StockAdjustment;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class StockAdjustmentPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'inventory.adjustments.view');
    }

    public function view(User $user, StockAdjustment $adjustment): bool
    {
        return $this->allows($user, 'inventory.adjustments.view', $adjustment);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'inventory.adjustments.create');
    }

    public function update(User $user, StockAdjustment $adjustment): bool
    {
        return $adjustment->isDraft() && $this->allows($user, 'inventory.adjustments.create', $adjustment);
    }

    public function delete(User $user, StockAdjustment $adjustment): bool
    {
        return $adjustment->isDraft() && $this->allows($user, 'inventory.adjustments.create', $adjustment);
    }

    /**
     * Contabilizar el ajuste es un permiso aparte del de capturarlo: quien
     * cuenta la mercadería no debería ser quien aprueba la diferencia.
     */
    public function post(User $user, StockAdjustment $adjustment): bool
    {
        return $adjustment->isDraft() && $this->allows($user, 'inventory.adjustments.post', $adjustment);
    }

    public function void(User $user, StockAdjustment $adjustment): bool
    {
        return $adjustment->isPosted() && $this->allows($user, 'inventory.adjustments.void', $adjustment);
    }
}
