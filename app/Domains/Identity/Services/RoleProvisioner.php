<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Alta de roles y permisos.
 *
 * Los permisos son globales (existen una sola vez en el sistema); los roles se
 * crean por empresa, porque una misma persona puede ser Contador en una empresa
 * y Vendedor en otra.
 */
final class RoleProvisioner
{
    /**
     * Crea los permisos que falten. Idempotente: se puede ejecutar en cada
     * despliegue para incorporar los permisos de módulos nuevos.
     */
    public function syncPermissions(): int
    {
        $existing = Permission::query()->pluck('name')->all();
        $created = 0;

        foreach (array_keys(PermissionCatalog::permissions()) as $name) {
            if (in_array($name, $existing, strict: true)) {
                continue;
            }

            Permission::create(['name' => $name, 'guard_name' => 'web']);
            $created++;
        }

        if ($created > 0) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $created;
    }

    /**
     * Crea los siete roles de la empresa con sus permisos.
     */
    public function provisionFor(Company $company): void
    {
        DB::transaction(function () use ($company): void {
            $this->syncPermissions();

            foreach (PermissionCatalog::roles() as $roleName => $permissions) {
                $role = Role::query()
                    ->where('name', $roleName)
                    ->where('company_id', $company->id)
                    ->first();

                // El team id se pasa explícito: este servicio corre al crear la
                // empresa, cuando la empresa activa del contexto es otra.
                $role ??= Role::create([
                    'name' => $roleName,
                    'guard_name' => 'web',
                    'company_id' => $company->id,
                ]);

                $role->syncPermissions($permissions);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    /**
     * Asigna un rol de una empresa concreta, sin depender de cuál esté activa.
     */
    public function assign(User $user, Company $company, string $roleName): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId($company->id);

        try {
            $role = Role::query()
                ->where('name', $roleName)
                ->where('company_id', $company->id)
                ->firstOrFail();

            $user->assignRole($role);
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
