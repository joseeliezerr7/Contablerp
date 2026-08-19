<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Policies;

use App\Domains\Tenancy\Models\Warehouse;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;

class WarehousePolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->has();
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $this->belongsToActiveCompany($warehouse);
    }

    public function create(User $user): bool
    {
        return $this->context->has();
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $this->belongsToActiveCompany($warehouse);
    }

    public function delete(User $user, Warehouse $warehouse): bool
    {
        // La bodega por defecto recibe las entradas de compras (Fase 5).
        return $this->belongsToActiveCompany($warehouse) && ! $warehouse->is_default;
    }

    private function belongsToActiveCompany(Warehouse $warehouse): bool
    {
        return $this->context->has() && $warehouse->company_id === $this->context->id();
    }
}
