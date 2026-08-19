<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Policies;

use App\Domains\Inventory\Models\InventoryStock;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

/**
 * Las existencias no se crean ni se editan a mano: son el resultado de los
 * movimientos. Solo se consultan, y se configuran sus puntos de reorden.
 */
class InventoryStockPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'inventory.stock.view');
    }

    public function view(User $user, InventoryStock $stock): bool
    {
        return $this->allows($user, 'inventory.stock.view', $stock);
    }

    public function update(User $user, InventoryStock $stock): bool
    {
        return $this->allows($user, 'inventory.stock.reorder', $stock);
    }
}
