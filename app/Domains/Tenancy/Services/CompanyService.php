<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Accounting\Services\ChartOfAccountsService;
use App\Domains\Accounting\Services\PeriodService;
use App\Domains\Billing\Services\QuotaService;
use App\Domains\Catalog\Services\CatalogProvisioner;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Identity\Services\RoleProvisioner;
use App\Domains\Tenancy\Enums\TenantStatus;
use App\Domains\Tenancy\Models\Company;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alta de empresas.
 *
 * Una empresa nunca nace vacía. Para poder operar necesita, en la misma
 * transacción: sucursal, bodega, plan de cuentas, cuentas por módulo, ejercicio
 * fiscal con sus períodos, y roles. Dejar cualquiera de esas piezas para
 * "después" produce empresas a medias en las que la primera factura falla.
 */
final class CompanyService
{
    public function __construct(
        private readonly ChartOfAccountsService $chartOfAccounts,
        private readonly PeriodService $periods,
        private readonly RoleProvisioner $roles,
        private readonly CatalogProvisioner $catalog,
        private readonly CompanyContext $context,
        private readonly QuotaService $quotas,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $owner): Company
    {
        return DB::transaction(function () use ($data, $owner): Company {
            $tenantId = $owner->tenant_id ?? $this->createTenantFor($owner, $data['legal_name']);

            // El plan contratado decide cuántas empresas caben. Se comprueba al
            // crear y no al usar: a un tenant que bajó de plan no se le quita
            // el acceso a lo que ya tenía, se le impide añadir más.
            $this->quotas->guardCompany(Tenant::query()->findOrFail($tenantId));

            $company = Company::create([...$data, 'tenant_id' => $tenantId]);

            $branch = $company->branches()->create([
                'code' => '001',
                'name' => 'Casa Matriz',
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'is_main' => true,
                'is_active' => true,
            ]);

            $company->warehouses()->create([
                'branch_id' => $branch->id,
                'code' => 'BOD01',
                'name' => 'Bodega Principal',
                'is_default' => true,
                'is_active' => true,
            ]);

            // Punto de emisión por defecto. Los códigos son los más comunes y
            // se corrigen en pantalla con los que asigne el SAR.
            //
            // **Sin CAI a propósito.** La autorización la emite la
            // administración tributaria y no se puede inventar: lo que se deja
            // preparado es el sitio donde cargarla. Hasta entonces la empresa
            // hace todo menos facturar, y al intentarlo el sistema dice
            // exactamente qué le falta.
            $company->fiscalPoints()->create([
                'branch_id' => $branch->id,
                'establishment_code' => '000',
                'emission_point_code' => '001',
                'name' => 'Caja principal',
                'is_active' => true,
            ]);

            // La configuración inicial consulta y escribe datos de la empresa
            // recién creada, no de la que el usuario tiene activa. Se activa
            // temporalmente para que el scope global apunte a la correcta.
            $this->context->runFor($company, function () use ($company): void {
                $this->chartOfAccounts->seedFor($company);
                $this->periods->createFiscalYear($company, (int) now()->format('Y'));
                $this->roles->provisionFor($company);
                // Después del plan de cuentas: los impuestos necesitan sus
                // cuentas de débito y crédito fiscal ya mapeadas.
                $this->catalog->provisionFor($company);
            });

            $owner->companies()->syncWithoutDetaching([
                $company->id => ['branch_id' => null],
            ]);

            $this->roles->assign($owner, $company, PermissionCatalog::ADMIN);

            if ($owner->default_company_id === null) {
                $owner->forceFill(['default_company_id' => $company->id])->save();
            }

            return $company;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, array $data): Company
    {
        return DB::transaction(function () use ($company, $data): Company {
            $company->update($data);

            return $company->refresh();
        });
    }

    /**
     * Un usuario que crea su primera empresa sin cuenta SaaS asociada obtiene
     * una. Evita dejar empresas huérfanas fuera de la jerarquía.
     */
    private function createTenantFor(User $owner, string $companyName): int
    {
        $tenant = Tenant::create([
            'name' => $companyName,
            'slug' => Str::slug($companyName).'-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Trial,
            'trial_ends_at' => now()->addDays(30),
        ]);

        $owner->forceFill(['tenant_id' => $tenant->id])->save();

        return $tenant->id;
    }
}
