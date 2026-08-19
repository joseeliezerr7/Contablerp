<?php

declare(strict_types=1);

namespace App\Domains\Taxation\Policies;

use App\Domains\Taxation\Models\Tax;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

/**
 * Un impuesto **no se elimina**: las facturas ya emitidas guardan su tasa
 * congelada, pero lo referencian, y borrarlo dejaría documentos apuntando a un
 * impuesto que no existe. Lo que se hace es desactivarlo, que es lo que ocurre
 * de verdad cuando el SAR cambia una tasa: la vieja deja de usarse en
 * documentos nuevos y sigue explicando los viejos.
 */
class TaxPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'catalog.taxes.view');
    }

    public function view(User $user, Tax $tax): bool
    {
        return $this->allows($user, 'catalog.taxes.view', $tax);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'catalog.taxes.manage');
    }

    public function update(User $user, Tax $tax): bool
    {
        return $this->allows($user, 'catalog.taxes.manage', $tax);
    }
}
