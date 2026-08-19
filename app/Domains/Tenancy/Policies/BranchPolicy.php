<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Policies;

use App\Domains\Tenancy\Models\Branch;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;

/**
 * El scope global ya impide *leer* sucursales de otra empresa. Esta policy
 * cierra el caso en que un id llega por otra vía (binding explícito, job,
 * consulta sin scope) y evita depender de una sola capa.
 */
class BranchPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->has();
    }

    public function view(User $user, Branch $branch): bool
    {
        return $this->belongsToActiveCompany($branch);
    }

    public function create(User $user): bool
    {
        return $this->context->has();
    }

    public function update(User $user, Branch $branch): bool
    {
        return $this->belongsToActiveCompany($branch);
    }

    public function delete(User $user, Branch $branch): bool
    {
        // La sucursal principal es el destino por defecto de los documentos.
        return $this->belongsToActiveCompany($branch) && ! $branch->is_main;
    }

    private function belongsToActiveCompany(Branch $branch): bool
    {
        return $this->context->has() && $branch->company_id === $this->context->id();
    }
}
