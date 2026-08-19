<?php

declare(strict_types=1);

namespace App\Domains\Assets\Policies;

use App\Domains\Assets\Models\FixedAssetCategory;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

/**
 * Las categorías de activo van con los permisos del módulo de activos: quien da
 * de alta un activo es quien necesita definir en qué categoría cae y contra qué
 * cuentas se registra.
 */
class FixedAssetCategoryPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'assets.view');
    }

    public function view(User $user, FixedAssetCategory $category): bool
    {
        return $this->allows($user, 'assets.view', $category);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'assets.manage');
    }

    public function update(User $user, FixedAssetCategory $category): bool
    {
        return $this->allows($user, 'assets.manage', $category);
    }

    /**
     * Solo se borra una categoría que nunca se usó.
     *
     * Con activos colgando, borrarla dejaría cada uno sin saber contra qué
     * cuenta deprecia. Las que ya se usaron se desactivan.
     */
    public function delete(User $user, FixedAssetCategory $category): bool
    {
        return ! $category->assets()->exists()
            && $this->allows($user, 'assets.manage', $category);
    }
}
