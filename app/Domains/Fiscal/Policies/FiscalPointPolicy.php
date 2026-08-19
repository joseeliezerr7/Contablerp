<?php

declare(strict_types=1);

namespace App\Domains\Fiscal\Policies;

use App\Domains\Fiscal\Models\FiscalPoint;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class FiscalPointPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'fiscal.points.view');
    }

    public function view(User $user, FiscalPoint $point): bool
    {
        return $this->allows($user, 'fiscal.points.view', $point);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'fiscal.points.manage');
    }

    public function update(User $user, FiscalPoint $point): bool
    {
        return $this->allows($user, 'fiscal.points.manage', $point);
    }

    /**
     * Un punto que ya emitió no se borra: sus documentos lo referencian, y el
     * número fiscal de una factura tiene que poder explicarse siempre. Lo que
     * se hace con un punto que dejó de usarse es desactivarlo.
     */
    public function delete(User $user, FiscalPoint $point): bool
    {
        return $this->allows($user, 'fiscal.points.manage', $point)
            && $point->authorizations()->doesntExist();
    }
}
