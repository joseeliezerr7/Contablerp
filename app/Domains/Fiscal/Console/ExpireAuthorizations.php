<?php

declare(strict_types=1);

namespace App\Domains\Fiscal\Console;

use App\Domains\Fiscal\Services\FiscalAuthorizationService;
use App\Domains\Tenancy\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Console\Command;

/**
 * Retira las autorizaciones del SAR cuyo plazo de emisión ya pasó.
 *
 * ## Por qué hace falta un comando
 *
 * `FiscalNumberService` ya se niega a numerar con un CAI vencido: emitir fuera
 * de plazo no puede ocurrir con o sin este comando. Lo que hace esto es que la
 * empresa **se entere antes** de que se le acabe el plazo, en vez de descubrirlo
 * el día que intenta facturarle a un cliente que está esperando de pie en el
 * mostrador. Al retirarlas, la pantalla de facturación las muestra vencidas y el
 * aviso de renovación aparece donde tiene que aparecer.
 *
 * ## Por qué recorre las empresas una por una
 *
 * `FiscalAuthorization` lleva el scope global de empresa, así que una consulta
 * sin contexto no devuelve nada. En consola no hay sesión ni empresa activa: se
 * entra en el contexto de cada una y se sale.
 */
class ExpireAuthorizations extends Command
{
    protected $signature = 'fiscal:expire-authorizations
                            {--company= : ID de la empresa; por defecto todas}';

    protected $description = 'Marca vencidas las autorizaciones del SAR cuya fecha límite ya pasó';

    public function handle(FiscalAuthorizationService $authorizations, CompanyContext $context): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get();

        $total = 0;

        foreach ($companies as $company) {
            $expired = $context->runFor(
                $company,
                fn (): int => $authorizations->expireOverdue(),
            );

            if ($expired > 0) {
                $this->line("  {$company->id}  {$company->legal_name}: {$expired} vencida(s)");
                $total += $expired;
            }
        }

        $this->info($total === 0
            ? 'Ninguna autorización venció hoy.'
            : "Se marcaron {$total} autorización(es) como vencidas.");

        return self::SUCCESS;
    }
}
