<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Exceptions\BillingException;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Company;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;

/**
 * Límites del plan.
 *
 * ## Se comprueban al crear, no al usar
 *
 * Un tenant que baja de plan puede quedar por encima del límite nuevo. Cortarle
 * el acceso a la tercera empresa que ya venía usando sería destruirle datos
 * suyos por una decisión comercial nuestra; lo que se le impide es **crear la
 * cuarta**. Por eso `remainingX()` puede devolver un número negativo y eso no
 * es un error: es un tenant por encima de su plan, que el panel del proveedor
 * debería mostrar para hablar con él.
 *
 * ## Sin suscripción no hay límites
 *
 * Un tenant sembrado a mano o migrado desde otro sistema puede no tener
 * suscripción todavía. En ese caso no se le bloquea nada: el sistema de cobro
 * no debe impedir que la contabilidad funcione.
 */
final class QuotaService
{
    /**
     * Suscripción viva del tenant, si tiene.
     */
    public function subscriptionFor(Tenant $tenant): ?Subscription
    {
        return Subscription::query()
            ->where('tenant_id', $tenant->id)
            ->live()
            ->first();
    }

    public function canAddCompany(Tenant $tenant): bool
    {
        return $this->remainingCompanies($tenant) === null || $this->remainingCompanies($tenant) > 0;
    }

    public function canAddUser(Tenant $tenant): bool
    {
        return $this->remainingUsers($tenant) === null || $this->remainingUsers($tenant) > 0;
    }

    public function canAddBranch(Tenant $tenant): bool
    {
        return $this->remainingBranches($tenant) === null || $this->remainingBranches($tenant) > 0;
    }

    /**
     * Lanza si el tenant ya llegó al límite de empresas.
     */
    public function guardCompany(Tenant $tenant): void
    {
        if (! $this->canAddCompany($tenant)) {
            throw BillingException::quotaReached('empresas', (int) $this->subscriptionFor($tenant)?->max_companies);
        }
    }

    public function guardUser(Tenant $tenant): void
    {
        if (! $this->canAddUser($tenant)) {
            throw BillingException::quotaReached('usuarios', (int) $this->subscriptionFor($tenant)?->max_users);
        }
    }

    public function guardBranch(Tenant $tenant): void
    {
        if (! $this->canAddBranch($tenant)) {
            throw BillingException::quotaReached('sucursales', (int) $this->subscriptionFor($tenant)?->max_branches);
        }
    }

    /**
     * Cuántas más caben. `null` es sin límite; un negativo es un tenant por
     * encima de su plan.
     */
    public function remainingCompanies(Tenant $tenant): ?int
    {
        $limit = $this->subscriptionFor($tenant)?->max_companies;

        return $limit === null ? null : $limit - $this->companyCount($tenant);
    }

    public function remainingUsers(Tenant $tenant): ?int
    {
        $limit = $this->subscriptionFor($tenant)?->max_users;

        return $limit === null ? null : $limit - $this->userCount($tenant);
    }

    public function remainingBranches(Tenant $tenant): ?int
    {
        $limit = $this->subscriptionFor($tenant)?->max_branches;

        return $limit === null ? null : $limit - $this->branchCount($tenant);
    }

    /**
     * Consumo actual del tenant, para el panel y para las cuotas.
     *
     * @return array{companies: int, users: int, branches: int}
     */
    public function usage(Tenant $tenant): array
    {
        return [
            'companies' => $this->companyCount($tenant),
            'users' => $this->userCount($tenant),
            'branches' => $this->branchCount($tenant),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Conteos
    |--------------------------------------------------------------------------
    |
    | Cuentan a través del tenant y no de la empresa activa: las cuotas son de
    | la cuenta entera, así que estas consultas cruzan empresas a propósito.
    */

    private function companyCount(Tenant $tenant): int
    {
        return Company::query()->where('tenant_id', $tenant->id)->count();
    }

    private function userCount(Tenant $tenant): int
    {
        return User::query()->where('tenant_id', $tenant->id)->count();
    }

    private function branchCount(Tenant $tenant): int
    {
        return Branch::acrossCompanies()
            ->whereIn('company_id', Company::query()->where('tenant_id', $tenant->id)->select('id'))
            ->count();
    }
}
