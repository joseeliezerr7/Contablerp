<?php

declare(strict_types=1);

namespace App\Domains\Assets\Policies;

use App\Domains\Assets\Models\FixedAsset;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class FixedAssetPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'assets.view');
    }

    public function view(User $user, FixedAsset $asset): bool
    {
        return $this->allows($user, 'assets.view', $asset);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'assets.manage');
    }

    public function update(User $user, FixedAsset $asset): bool
    {
        return ! $asset->isDisposed() && $this->allows($user, 'assets.manage', $asset);
    }

    public function delete(User $user, FixedAsset $asset): bool
    {
        // Un activo que ya depreció dejó rastro en el libro: se da de baja, no
        // se borra.
        return $asset->accumulated()->isZero()
            && ! $asset->isDisposed()
            && $this->allows($user, 'assets.manage', $asset);
    }

    public function dispose(User $user, FixedAsset $asset): bool
    {
        return ! $asset->isDisposed() && $this->allows($user, 'assets.dispose', $asset);
    }
}
