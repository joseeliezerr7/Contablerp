<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Company;
use Closure;
use RuntimeException;

/**
 * Empresa (y sucursal) activas durante el ciclo de vida de la petición.
 *
 * Se registra como singleton. Todo el aislamiento multi-tenant depende de este
 * objeto: el scope global lee de aquí, el trait BelongsToCompany asigna desde
 * aquí, y las reglas de validación comparan contra aquí.
 */
final class CompanyContext
{
    private ?Company $company = null;

    private ?Branch $branch = null;

    /**
     * Cuando es true, el scope global no filtra ni lanza excepción. Reservado
     * para seeders, migraciones de datos y el panel de superadmin.
     */
    private bool $unscoped = false;

    public function set(Company $company, ?Branch $branch = null): void
    {
        if ($branch !== null && $branch->company_id !== $company->id) {
            throw new RuntimeException(
                "La sucursal {$branch->id} no pertenece a la empresa {$company->id}."
            );
        }

        $this->company = $company;
        $this->branch = $branch;
    }

    public function setBranch(?Branch $branch): void
    {
        if ($branch !== null && $branch->company_id !== $this->id()) {
            throw new RuntimeException(
                "La sucursal {$branch->id} no pertenece a la empresa activa."
            );
        }

        $this->branch = $branch;
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    public function branch(): ?Branch
    {
        return $this->branch;
    }

    public function id(): ?int
    {
        return $this->company?->id;
    }

    public function branchId(): ?int
    {
        return $this->branch?->id;
    }

    public function has(): bool
    {
        return $this->company !== null;
    }

    /**
     * Empresa activa o excepción. Úsalo en servicios donde operar sin empresa
     * sería un error de programación, no un caso de negocio.
     */
    public function companyOrFail(): Company
    {
        return $this->company ?? throw new MissingCompanyContextException(
            'No hay una empresa activa en el contexto.'
        );
    }

    public function idOrFail(): int
    {
        return $this->companyOrFail()->id;
    }

    public function isUnscoped(): bool
    {
        return $this->unscoped;
    }

    public function clear(): void
    {
        $this->company = null;
        $this->branch = null;
    }

    /**
     * Ejecuta el callback con otra empresa activa y restaura el estado previo,
     * incluso si el callback lanza. Pensado para jobs, comandos y pruebas.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Company $company, Closure $callback, ?Branch $branch = null): mixed
    {
        $previousCompany = $this->company;
        $previousBranch = $this->branch;

        $this->set($company, $branch);

        try {
            return $callback();
        } finally {
            $this->company = $previousCompany;
            $this->branch = $previousBranch;
        }
    }

    /**
     * Desactiva el filtrado por empresa dentro del callback. Es una puerta
     * trasera deliberadamente explícita y ruidosa: si aparece en un controlador
     * o en un componente Livewire, es un bug de seguridad.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function unscoped(Closure $callback): mixed
    {
        $previous = $this->unscoped;
        $this->unscoped = true;

        try {
            return $callback();
        } finally {
            $this->unscoped = $previous;
        }
    }
}
