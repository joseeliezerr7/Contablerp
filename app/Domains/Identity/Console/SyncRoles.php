<?php

declare(strict_types=1);

namespace App\Domains\Identity\Console;

use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Identity\Services\RoleProvisioner;
use App\Domains\Tenancy\Models\Company;
use Illuminate\Console\Command;

/**
 * Re-siembra los roles de las empresas ya creadas contra el catálogo actual.
 *
 * ## Por qué hace falta
 *
 * `CompanyService` provisiona los roles **una vez**, al crear la empresa. Cuando
 * una versión nueva añade permisos —o cambia lo que puede hacer un rol, como
 * el cajero, que pasó a poder facturar en el mostrador—, las empresas
 * que ya existen se quedan con los roles de antes. El síntoma es desconcertante:
 * el código dice que el cajero puede vender, la pantalla le responde 403, y no
 * hay nada mal en el código.
 *
 * Es idempotente: correrlo dos veces no cambia nada. Debe ejecutarse en cada
 * despliegue que toque `PermissionCatalog`.
 *
 * ## Qué NO toca
 *
 * Los roles asignados a cada usuario. Solo sincroniza qué permisos tiene cada
 * rol; quién es Cajero y quién Contador sigue siendo decisión de la empresa.
 */
class SyncRoles extends Command
{
    protected $signature = 'identity:sync-roles
                            {--company= : ID de la empresa; por defecto todas}';

    protected $description = 'Sincroniza los permisos de cada rol con el catálogo actual';

    public function handle(RoleProvisioner $provisioner): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            $this->error('No se encontraron empresas.');

            return self::FAILURE;
        }

        $created = $provisioner->syncPermissions();

        if ($created > 0) {
            $this->line("Permisos nuevos en el catálogo: {$created}");
        }

        foreach ($companies as $company) {
            $provisioner->provisionFor($company);
            $this->line("  {$company->id}  {$company->legal_name}");
        }

        $this->info(sprintf(
            'Roles sincronizados en %d empresa(s) sobre %d permisos y %d roles.',
            $companies->count(),
            count(PermissionCatalog::permissions()),
            count(PermissionCatalog::roles()),
        ));

        return self::SUCCESS;
    }
}
