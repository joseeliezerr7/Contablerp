<?php

declare(strict_types=1);

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Enums\JournalEntryType;
use App\Domains\Accounting\Exceptions\AccountingException;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Accounting\Services\ClosingService;
use App\Domains\Accounting\Services\FinancialStatementService;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\PeriodService;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->engine = app(AccountingEngine::class);
    $this->closing = app(ClosingService::class);
    $this->statements = app(FinancialStatementService::class);
    $this->periods = app(PeriodService::class);

    $this->year = (int) now()->format('Y');
    $this->fiscalYear = FiscalYear::query()->firstOrFail();
});

/**
 * Cierra los once primeros períodos, que es el requisito para cerrar el
 * ejercicio: el duodécimo queda abierto para recibir la partida de cierre.
 */
function closeAllButLastPeriod(PeriodService $periods, FiscalYear $year): void
{
    $year->periods()->where('number', '<', 12)->orderBy('number')->get()
        ->each(fn ($period) => $periods->close($period));
}

it('cancela las cuentas de resultado y traslada la utilidad', function () {
    postSampleYear($this->engine, $this->year);
    closeAllButLastPeriod($this->periods, $this->fiscalYear);

    $result = $this->closing->closeFiscalYear($this->fiscalYear, $this->user->id);

    expect($result['net_profit']->toString())->toBe('24000.0000')
        ->and($result['entries'])->toHaveCount(2);

    // Tras el cierre, las cuentas de resultado quedan en cero. Se consulta el
    // mayor del año completo, que incluye la partida de cierre del 31/12.
    $ledger = app(LedgerQueryService::class);

    foreach (['4.1.01', '5.1.01', '6.1.03'] as $code) {
        $mayor = $ledger->ledgerFor(account($code), "{$this->year}-01-01", "{$this->year}-12-31");

        expect($mayor['closing']->isZero())->toBeTrue("La cuenta {$code} no quedó saldada");
    }

    // …y la utilidad vive en Utilidades Retenidas.
    $retenidas = $ledger->ledgerFor(account('3.2.01'), "{$this->year}-01-01", "{$this->year}-12-31");
    expect($retenidas['closing']->toString())->toBe('24000.0000');
});

it('deja el Resumen de Resultados saldado', function () {
    postSampleYear($this->engine, $this->year);
    closeAllButLastPeriod($this->periods, $this->fiscalYear);

    $this->closing->closeFiscalYear($this->fiscalYear, $this->user->id);

    $resumen = app(LedgerQueryService::class)
        ->ledgerFor(account('3.2.04'), "{$this->year}-01-01", "{$this->year}-12-31");

    expect($resumen['closing']->isZero())->toBeTrue();
});

it('mantiene el balance general cuadrado después del cierre', function () {
    postSampleYear($this->engine, $this->year);
    closeAllButLastPeriod($this->periods, $this->fiscalYear);

    $antes = $this->statements->balanceSheet("{$this->year}-12-31");
    $this->closing->closeFiscalYear($this->fiscalYear, $this->user->id);
    $despues = $this->statements->balanceSheet("{$this->year}-12-31");

    expect($antes['balanced'])->toBeTrue()
        ->and($despues['balanced'])->toBeTrue()
        // El activo no cambia: el cierre solo mueve cuentas de resultado y
        // patrimonio.
        ->and($despues['total_assets']->equals($antes['total_assets']))->toBeTrue()
        // La utilidad del ejercicio pasa a cero porque ya está en el patrimonio.
        ->and($despues['profit']->isZero())->toBeTrue()
        ->and($despues['total_equity']->equals($antes['total_equity']->plus($antes['profit'])))->toBeTrue();
});

it('permite seguir imprimiendo el estado de resultados de un ejercicio cerrado', function () {
    postSampleYear($this->engine, $this->year);
    closeAllButLastPeriod($this->periods, $this->fiscalYear);

    $antes = $this->statements->incomeStatement("{$this->year}-01-01", "{$this->year}-12-31");
    $this->closing->closeFiscalYear($this->fiscalYear, $this->user->id);
    $despues = $this->statements->incomeStatement("{$this->year}-01-01", "{$this->year}-12-31");

    // Las partidas de cierre se excluyen: si se incluyeran, el reporte saldría
    // en cero y el contador no podría reimprimirlo.
    expect($despues['net_profit']->equals($antes['net_profit']))->toBeTrue()
        ->and($despues['total_income']->toString())->toBe('120000.0000');
});

it('marca el ejercicio y todos sus períodos como cerrados', function () {
    postSampleYear($this->engine, $this->year);
    closeAllButLastPeriod($this->periods, $this->fiscalYear);

    $this->closing->closeFiscalYear($this->fiscalYear, $this->user->id);

    expect($this->fiscalYear->refresh()->status)->toBe(FiscalYearStatus::Closed)
        ->and($this->fiscalYear->closed_by)->toBe($this->user->id)
        ->and($this->fiscalYear->periods()->where('status', 'open')->count())->toBe(0);
});

it('genera las partidas de cierre con el tipo correcto y la fecha del último día', function () {
    postSampleYear($this->engine, $this->year);
    closeAllButLastPeriod($this->periods, $this->fiscalYear);

    $result = $this->closing->closeFiscalYear($this->fiscalYear, $this->user->id);

    foreach ($result['entries'] as $entry) {
        expect($entry->type)->toBe(JournalEntryType::Closing)
            ->and($entry->date->format('Y-m-d'))->toBe("{$this->year}-12-31")
            ->and($entry->isBalanced())->toBeTrue()
            ->and($entry->isPosted())->toBeTrue();
    }
});

it('impide contabilizar en un ejercicio cerrado', function () {
    postSampleYear($this->engine, $this->year);
    closeAllButLastPeriod($this->periods, $this->fiscalYear);
    $this->closing->closeFiscalYear($this->fiscalYear, $this->user->id);

    expect(fn () => $this->engine->post(
        JournalDraft::on("{$this->year}-06-15", 'Tardía')
            ->debit(account('1.1.02.01')->id, '100.00')
            ->credit(account('4.1.01')->id, '100.00')
    ))->toThrow(AccountingException::class);
});

it('no cierra si quedan períodos abiertos antes del último', function () {
    postSampleYear($this->engine, $this->year);

    expect(fn () => $this->closing->closeFiscalYear($this->fiscalYear))
        ->toThrow(AccountingException::class, 'períodos abiertos');
});

it('no cierra si hay partidas en borrador', function () {
    postSampleYear($this->engine, $this->year);
    closeAllButLastPeriod($this->periods, $this->fiscalYear);

    $this->engine->saveDraft(
        JournalDraft::on("{$this->year}-12-10", 'Borrador pendiente')
            ->debit(account('1.1.02.01')->id, '100.00')
            ->credit(account('4.1.01')->id, '100.00')
    );

    expect(fn () => $this->closing->closeFiscalYear($this->fiscalYear))
        ->toThrow(AccountingException::class, 'borrador');
});

it('no cierra dos veces el mismo ejercicio', function () {
    postSampleYear($this->engine, $this->year);
    closeAllButLastPeriod($this->periods, $this->fiscalYear);
    $this->closing->closeFiscalYear($this->fiscalYear);

    expect(fn () => $this->closing->closeFiscalYear($this->fiscalYear->refresh()))
        ->toThrow(AccountingException::class, 'ya está cerrado');
});

it('enumera los impedimentos antes de intentar el cierre', function () {
    postSampleYear($this->engine, $this->year);

    $problemas = $this->closing->blockers($this->fiscalYear);

    expect($problemas)->not->toBeEmpty()
        ->and(implode(' ', $problemas))->toContain('períodos abiertos');
});

it('cierra un ejercicio con pérdida llevándola a utilidades retenidas', function () {
    // Solo gastos: el ejercicio cierra en pérdida.
    $this->engine->post(
        JournalDraft::on("{$this->year}-02-10", 'Gasto sin ingresos')
            ->debit(account('6.1.03')->id, '5000.00')
            ->credit(account('1.1.02.01')->id, '5000.00')
    );

    closeAllButLastPeriod($this->periods, $this->fiscalYear);

    $result = $this->closing->closeFiscalYear($this->fiscalYear);

    expect($result['net_profit']->toString())->toBe('-5000.0000');

    $retenidas = app(LedgerQueryService::class)
        ->ledgerFor(account('3.2.01'), "{$this->year}-01-01", "{$this->year}-12-31");

    // Patrimonio es acreedora: una pérdida deja saldo negativo.
    expect($retenidas['closing']->toString())->toBe('-5000.0000')
        ->and($this->statements->balanceSheet("{$this->year}-12-31")['balanced'])->toBeTrue();
});

it('cierra un ejercicio sin movimientos sin generar partidas', function () {
    closeAllButLastPeriod($this->periods, $this->fiscalYear);

    $result = $this->closing->closeFiscalYear($this->fiscalYear);

    expect($result['entries'])->toBeEmpty()
        ->and($result['net_profit']->isZero())->toBeTrue()
        ->and($this->fiscalYear->refresh()->status)->toBe(FiscalYearStatus::Closed);
});

it('arrastra los saldos de balance al ejercicio siguiente sin partida de apertura', function () {
    postSampleYear($this->engine, $this->year);
    closeAllButLastPeriod($this->periods, $this->fiscalYear);
    $this->closing->closeFiscalYear($this->fiscalYear);

    $siguiente = $this->periods->createFiscalYear($this->company, $this->year + 1);

    // El libro es continuo: el banco conserva su saldo sin necesidad de una
    // partida de apertura que lo volvería a contar.
    $bancoAlCierre = $this->statements->balanceSheet("{$this->year}-12-31")['total_assets'];
    $bancoAlInicio = $this->statements->balanceSheet(($this->year + 1).'-01-01')['total_assets'];

    expect($bancoAlInicio->equals($bancoAlCierre))->toBeTrue()
        ->and($siguiente->periods()->count())->toBe(12)
        ->and($this->statements->balanceSheet(($this->year + 1).'-01-01')['balanced'])->toBeTrue();
});
