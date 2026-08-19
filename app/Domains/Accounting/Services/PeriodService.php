<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Enums\PeriodStatus;
use App\Domains\Accounting\Exceptions\AccountingException;
use App\Domains\Accounting\Exceptions\ClosedPeriodException;
use App\Domains\Accounting\Models\AccountingPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Ejercicios fiscales y períodos contables.
 *
 * El período es la unidad de control: mientras está abierto acepta partidas,
 * y al cerrarse congela los saldos de ese mes.
 */
final class PeriodService
{
    /**
     * Crea un ejercicio con sus 12 períodos mensuales, respetando el mes de
     * inicio configurado en la empresa (no siempre es enero).
     */
    public function createFiscalYear(Company $company, int $startYear): FiscalYear
    {
        return DB::transaction(function () use ($company, $startYear): FiscalYear {
            $startMonth = $company->fiscal_year_start_month;
            $startsOn = CarbonImmutable::create($startYear, $startMonth, 1);
            $endsOn = $startsOn->addYear()->subDay();

            // Un ejercicio que arranca en abril de 2026 termina en marzo de 2027,
            // así que se nombra con ambos años para no confundir al contador.
            $name = $startMonth === 1
                ? (string) $startYear
                : $startsOn->format('Y').'-'.$endsOn->format('Y');

            $exists = $company->fiscalYears()->where('name', $name)->exists();

            if ($exists) {
                throw new AccountingException("El ejercicio fiscal {$name} ya existe.");
            }

            $fiscalYear = new FiscalYear;
            $fiscalYear->forceFill([
                'company_id' => $company->id,
                'name' => $name,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
                'status' => FiscalYearStatus::Open,
            ])->save();

            for ($number = 1; $number <= 12; $number++) {
                $periodStart = $startsOn->addMonths($number - 1);

                $period = new AccountingPeriod;
                $period->forceFill([
                    'company_id' => $company->id,
                    'fiscal_year_id' => $fiscalYear->id,
                    'number' => $number,
                    'name' => ucfirst($periodStart->locale('es')->monthName).' '.$periodStart->format('Y'),
                    'starts_on' => $periodStart->toDateString(),
                    'ends_on' => $periodStart->endOfMonth()->toDateString(),
                    'status' => PeriodStatus::Open,
                ])->save();
            }

            return $fiscalYear->load('periods');
        });
    }

    /**
     * Período que contiene la fecha, o excepción. Es la guarda que usa el motor
     * contable antes de escribir cualquier partida.
     */
    public function periodFor(DateTimeInterface $date): AccountingPeriod
    {
        $period = AccountingPeriod::query()->containing($date)->first();

        if ($period === null) {
            throw ClosedPeriodException::noPeriodFor($date);
        }

        return $period;
    }

    /**
     * Período abierto que contiene la fecha, o excepción.
     */
    public function openPeriodFor(DateTimeInterface $date): AccountingPeriod
    {
        $period = $this->periodFor($date);

        if (! $period->acceptsPostings()) {
            throw ClosedPeriodException::forPeriod($period);
        }

        if (! $period->fiscalYear->acceptsPostings()) {
            throw new AccountingException(
                "El ejercicio fiscal {$period->fiscalYear->name} está cerrado y no admite movimientos."
            );
        }

        return $period;
    }

    /**
     * Cierra un período. Exige que los anteriores ya estén cerrados: cerrar
     * marzo dejando febrero abierto permitiría que aparecieran movimientos
     * anteriores a un cierre ya declarado.
     */
    public function close(AccountingPeriod $period, ?int $userId = null): AccountingPeriod
    {
        if ($period->status !== PeriodStatus::Open) {
            throw new AccountingException("El período {$period->name} no está abierto.");
        }

        $previousOpen = AccountingPeriod::query()
            ->where('fiscal_year_id', $period->fiscal_year_id)
            ->where('number', '<', $period->number)
            ->where('status', PeriodStatus::Open)
            ->orderBy('number')
            ->first();

        if ($previousOpen !== null) {
            throw new AccountingException(sprintf(
                'No se puede cerrar %s mientras %s siga abierto. Los períodos se cierran en orden.',
                $period->name,
                $previousOpen->name,
            ));
        }

        $draftCount = $period->journalEntries()->where('status', 'draft')->count();

        if ($draftCount > 0) {
            throw new AccountingException(sprintf(
                'El período %s tiene %d partida(s) en borrador. Contabilízalas o elimínalas antes de cerrar.',
                $period->name,
                $draftCount,
            ));
        }

        $period->forceFill([
            'status' => PeriodStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $userId,
        ])->save();

        return $period;
    }

    /**
     * Reabre un período cerrado. Exige que los posteriores sigan abiertos, para
     * no dejar un período abierto entre dos cerrados.
     */
    public function reopen(AccountingPeriod $period): AccountingPeriod
    {
        if (! $period->status->canReopen()) {
            throw new AccountingException(sprintf(
                'El período %s está %s y no puede reabrirse.',
                $period->name,
                mb_strtolower($period->status->label()),
            ));
        }

        $laterClosed = AccountingPeriod::query()
            ->where('fiscal_year_id', $period->fiscal_year_id)
            ->where('number', '>', $period->number)
            ->whereIn('status', [PeriodStatus::Closed, PeriodStatus::Locked])
            ->orderBy('number')
            ->first();

        if ($laterClosed !== null) {
            throw new AccountingException(sprintf(
                'No se puede reabrir %s porque %s ya está cerrado. Reabre primero los períodos posteriores.',
                $period->name,
                $laterClosed->name,
            ));
        }

        $period->forceFill([
            'status' => PeriodStatus::Open,
            'closed_at' => null,
            'closed_by' => null,
        ])->save();

        return $period;
    }

    /**
     * Bloqueo definitivo: el período ya fue declarado o auditado y no admite
     * reapertura desde la aplicación.
     */
    public function lock(AccountingPeriod $period, ?int $userId = null): AccountingPeriod
    {
        if ($period->status === PeriodStatus::Open) {
            $this->close($period, $userId);
        }

        $period->forceFill(['status' => PeriodStatus::Locked])->save();

        return $period;
    }
}
