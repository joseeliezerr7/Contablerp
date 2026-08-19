<?php

declare(strict_types=1);

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Enums\PeriodStatus;
use App\Domains\Accounting\Exceptions\AccountingException;
use App\Domains\Accounting\Models\AccountingPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Accounting\Services\ClosingService;
use App\Domains\Accounting\Services\PeriodService;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->service = app(PeriodService::class);
});

it('crea el ejercicio con sus doce períodos mensuales', function () {
    $year = FiscalYear::query()->firstOrFail();

    expect($year->periods()->count())->toBe(12)
        ->and($year->periods()->first()->name)->toBe('Enero '.now()->format('Y'))
        ->and($year->starts_on->format('m-d'))->toBe('01-01')
        ->and($year->ends_on->format('m-d'))->toBe('12-31');
});

it('respeta un ejercicio fiscal que no empieza en enero', function () {
    $this->company->forceFill(['fiscal_year_start_month' => 4])->save();

    $year = $this->service->createFiscalYear($this->company->refresh(), 2027);

    expect($year->name)->toBe('2027-2028')
        ->and($year->starts_on->format('Y-m-d'))->toBe('2027-04-01')
        ->and($year->ends_on->format('Y-m-d'))->toBe('2028-03-31')
        // La relación ya viene ordenada por número, así que se toman los
        // extremos de la colección en vez de reordenar la consulta.
        ->and($year->periods->first()->name)->toBe('Abril 2027')
        ->and($year->periods->last()->name)->toBe('Marzo 2028');
});

it('no duplica un ejercicio existente', function () {
    expect(fn () => $this->service->createFiscalYear($this->company, (int) now()->format('Y')))
        ->toThrow(AccountingException::class, 'ya existe');
});

it('encuentra el período que contiene una fecha', function () {
    $periodo = $this->service->periodFor(new DateTimeImmutable(now()->format('Y').'-07-15'));

    expect($periodo->number)->toBe(7)
        ->and($periodo->name)->toBe('Julio '.now()->format('Y'));
});

it('exige cerrar los períodos en orden', function () {
    $marzo = periodFor(now()->format('Y').'-03-15');

    expect(fn () => $this->service->close($marzo))
        ->toThrow(AccountingException::class, 'Los períodos se cierran en orden');
});

it('resume los períodos abiertos en un solo mensaje al bloquear el cierre', function () {
    $problemas = app(ClosingService::class)
        ->blockers(FiscalYear::query()->firstOrFail());

    // Once mensajes casi idénticos llenarían la pantalla; se agrupan en uno.
    expect($problemas)->toHaveCount(1)
        ->and($problemas[0])->toContain('11 períodos abiertos')
        ->and($problemas[0])->toContain('Enero')
        ->and($problemas[0])->toContain('Noviembre');
});

it('cierra un período', function () {
    $enero = periodFor(now()->format('Y').'-01-15');

    $this->service->close($enero, $this->user->id);

    expect($enero->refresh()->status)->toBe(PeriodStatus::Closed)
        ->and($enero->closed_by)->toBe($this->user->id)
        ->and($enero->closed_at)->not->toBeNull();
});

it('no cierra un período con partidas en borrador', function () {
    app(AccountingEngine::class)->saveDraft(
        JournalDraft::on(now()->format('Y').'-01-10', 'Borrador pendiente')
            ->debit(account('1.1.03.01')->id, '100.00')
            ->credit(account('4.1.01')->id, '100.00')
    );

    expect(fn () => $this->service->close(periodFor(now()->format('Y').'-01-15')))
        ->toThrow(AccountingException::class, 'en borrador');
});

it('reabre un período cerrado', function () {
    $enero = periodFor(now()->format('Y').'-01-15');
    $this->service->close($enero);

    $this->service->reopen($enero->refresh());

    expect($enero->refresh()->status)->toBe(PeriodStatus::Open)
        ->and($enero->closed_at)->toBeNull();
});

it('no reabre un período si ya se cerró uno posterior', function () {
    $enero = periodFor(now()->format('Y').'-01-15');
    $febrero = periodFor(now()->format('Y').'-02-15');

    $this->service->close($enero);
    $this->service->close($febrero);

    expect(fn () => $this->service->reopen($enero->refresh()))
        ->toThrow(AccountingException::class, 'Reabre primero los períodos posteriores');
});

it('no reabre un período bloqueado', function () {
    $enero = periodFor(now()->format('Y').'-01-15');
    $this->service->lock($enero);

    expect($enero->refresh()->status)->toBe(PeriodStatus::Locked);

    expect(fn () => $this->service->reopen($enero->refresh()))
        ->toThrow(AccountingException::class, 'no puede reabrirse');
});

it('aísla los períodos entre empresas', function () {
    $propios = AccountingPeriod::query()->count();
    accountingCompany();

    expect(AccountingPeriod::query()->count())->toBe($propios);
});
