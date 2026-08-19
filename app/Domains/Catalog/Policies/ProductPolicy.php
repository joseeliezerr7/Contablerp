<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Policies;

use App\Domains\Catalog\Models\Product;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class ProductPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'catalog.products.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $this->allows($user, 'catalog.products.view', $product);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'catalog.products.create');
    }

    public function update(User $user, Product $product): bool
    {
        return $this->allows($user, 'catalog.products.update', $product);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->allows($user, 'catalog.products.delete', $product);
    }

    /**
     * El costo es información sensible: un vendedor con acceso al catálogo no
     * debe poder deducir el margen de la empresa.
     */
    public function viewCost(User $user, ?Product $product = null): bool
    {
        return $this->allows($user, 'catalog.products.view_cost', $product);
    }
}
