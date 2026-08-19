<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Policies;

use App\Domains\Inventory\Models\InventoryMovement;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

/**
 * El kardex es de solo lectura por definición. No hay `update` ni `delete`
 * porque no existen: un movimiento equivocado se corrige con un ajuste, igual
 * que una partida contable equivocada se corrige con otra partida.
 */
class InventoryMovementPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'inventory.kardex.view');
    }

    public function view(User $user, InventoryMovement $movement): bool
    {
        return $this->allows($user, 'inventory.kardex.view', $movement);
    }
}
