<?php

declare(strict_types=1);

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\DataTransfer\StatementRow;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Accounting\Services\CashFlowService;
use App\Domains\Accounting\Services\FinancialStatementService;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Support\Money;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->engine = app(AccountingEngine::class);
    $this->statements = app(FinancialStatementService::class);
    $this->cashFlow = app(CashFlowService::class);

    $this->year = (int) now()->format('Y');
});

/**
 * Ejercicio completo de una PyME: capital, compra, venta, costo, cobro, gasto
 * y compra de activo fijo. Cubre las tres clasificaciones de flujo.
 */
function postSampleYear(AccountingEngine $engine, int $year): void
{
    $banco = account('1.1.02.01')->id;
    $clientes = account('1.1.03.01')->id;
    $inventario = account('1.1.04.01')->id;
    $isvAcreditable = account('1.1.05.01')->id;
    $equipo = account('1.2.01.04')->id;
    $proveedores = account('2.1.01.01')->id;
    $isvPorPagar = account('2.1.02.01')->id;
    $prestamo = account('2.2.01.01')->id;
    $capital = account('3.1.01')->id;
    $ventas = account('4.1.01')->id;
    $costo = account('5.1.01')->id;
    $alquiler = account('6.1.03')->id;

    // Financiamiento: aporte de capital en efectivo.
    $engine->post(JournalDraft::on("{$year}-01-10", 'Aporte de capital')
        ->debit($banco, '200000.00')->credit($capital, '200000.00'));

    // Financiamiento: préstamo bancario.
    $engine->post(JournalDraft::on("{$year}-01-15", 'Préstamo bancario')
        ->debit($banco, '50000.00')->credit($prestamo, '50000.00'));

    // Inversión: compra de equipo de cómputo al contado.
    $engine->post(JournalDraft::on("{$year}-02-01", 'Compra de equipo de cómputo')
        ->debit($equipo, '30000.00')->credit($banco, '30000.00'));

    // Operación: compra de mercadería al crédito (no mueve caja).
    $engine->post(JournalDraft::on("{$year}-02-10", 'Compra de mercadería')
        ->debit($inventario, '80000.00')
        ->debit($isvAcreditable, '12000.00')
        ->credit($proveedores, '92000.00'));

    // Operación: pago al proveedor.
    $engine->post(JournalDraft::on("{$year}-02-20", 'Pago a proveedor')
        ->debit($proveedores, '92000.00')->credit($banco, '92000.00'));

    // Operación: venta al crédito (no mueve caja).
    $engine->post(JournalDraft::on("{$year}-03-05", 'Venta FAC-001')
        ->debit($clientes, '138000.00')
        ->credit($ventas, '120000.00')
        ->credit($isvPorPagar, '18000.00'));

    // Operación: costo de la venta.
    $engine->post(JournalDraft::on("{$year}-03-05", 'Costo de la venta FAC-001')
        ->debit($costo, '72000.00')->credit($inventario, '72000.00'));

    // Operación: cobro al cliente.
    $engine->post(JournalDraft::on("{$year}-03-20", 'Cobro FAC-001')
        ->debit($banco, '100000.00')->credit($clientes, '100000.00'));

    // Operación: gasto de alquiler pagado.
    $engine->post(JournalDraft::on("{$year}-04-01", 'Alquiler del local')
        ->debit($alquiler, '24000.00')->credit($banco, '24000.00'));
}

/*
|--------------------------------------------------------------------------
| Balance de comprobación
|--------------------------------------------------------------------------
*/

it('cuadra el balance de comprobación', function () {
    postSampleYear($this->engine, $this->year);

    $tb = $this->statements->trialBalance("{$this->year}-01-01", "{$this->year}-12-31");

    expect($tb['balanced'])->toBeTrue()
        ->and($tb['debit']->equals($tb['credit']))->toBeTrue()
        ->and($tb['closing_debit']->equals($tb['closing_credit']))->toBeTrue()
        ->and($tb['debit']->isPositive())->toBeTrue();
});

it('arrastra el saldo inicial al balance de comprobación del período siguiente', function () {
    postSampleYear($this->engine, $this->year);

    $marzo = $this->statements->trialBalance("{$this->year}-03-01", "{$this->year}-03-31");

    $banco = $marzo['rows']->first(fn (StatementRow $r) => $r->code === '1.1.02.01');

    // Enero y febrero: 200.000 + 50.000 − 30.000 − 92.000 = 128.000
    expect($banco->opening->toString())->toBe('128000.0000')
        ->and($banco->debit->toString())->toBe('100000.0000')
        ->and($banco->closing->toString())->toBe('228000.0000');
});

it('coloca cada cuenta en la columna que le corresponde', function () {
    postSampleYear($this->engine, $this->year);

    $tb = $this->statements->trialBalance("{$this->year}-01-01", "{$this->year}-12-31");

    $banco = $tb['rows']->first(fn (StatementRow $r) => $r->code === '1.1.02.01');
    $proveedores = $tb['rows']->first(fn (StatementRow $r) => $r->code === '2.1.02.01');

    // El activo cae del lado deudor; el pasivo, del acreedor.
    expect($banco->debitBalance()->isPositive())->toBeTrue()
        ->and($banco->creditBalance()->isZero())->toBeTrue()
        ->and($proveedores->creditBalance()->isPositive())->toBeTrue()
        ->and($proveedores->debitBalance()->isZero())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Estado de resultados
|--------------------------------------------------------------------------
*/

it('calcula la utilidad bruta y la neta', function () {
    postSampleYear($this->engine, $this->year);

    $er = $this->statements->incomeStatement("{$this->year}-01-01", "{$this->year}-12-31");

    expect($er['total_income']->toString())->toBe('120000.0000')
        ->and($er['total_cost']->toString())->toBe('72000.0000')
        ->and($er['gross_profit']->toString())->toBe('48000.0000')
        ->and($er['total_expense']->toString())->toBe('24000.0000')
        ->and($er['net_profit']->toString())->toBe('24000.0000');
});

it('excluye del estado de resultados las cuentas de balance', function () {
    postSampleYear($this->engine, $this->year);

    $er = $this->statements->incomeStatement("{$this->year}-01-01", "{$this->year}-12-31");

    $codes = collect([...$er['income'], ...$er['cost'], ...$er['expense']])
        ->map(fn (StatementRow $r) => $r->code);

    expect($codes)->not->toContain('1.1.02.01')
        ->and($codes)->not->toContain('2.1.01.01')
        ->and($codes)->toContain('4.1.01');
});

it('mide solo el movimiento del rango pedido', function () {
    postSampleYear($this->engine, $this->year);

    $marzo = $this->statements->incomeStatement("{$this->year}-03-01", "{$this->year}-03-31");

    // El alquiler es de abril: no debe aparecer en marzo.
    expect($marzo['total_income']->toString())->toBe('120000.0000')
        ->and($marzo['total_expense']->isZero())->toBeTrue()
        ->and($marzo['net_profit']->toString())->toBe('48000.0000');
});

/*
|--------------------------------------------------------------------------
| Balance general
|--------------------------------------------------------------------------
*/

it('cuadra el balance general', function () {
    postSampleYear($this->engine, $this->year);

    $bg = $this->statements->balanceSheet("{$this->year}-12-31");

    expect($bg['balanced'])->toBeTrue()
        ->and($bg['difference']->isZero())->toBeTrue()
        ->and($bg['total_assets']->isPositive())->toBeTrue();
});

it('refleja en el balance la utilidad del estado de resultados', function () {
    postSampleYear($this->engine, $this->year);

    $bg = $this->statements->balanceSheet("{$this->year}-12-31");
    $er = $this->statements->incomeStatement("{$this->year}-01-01", "{$this->year}-12-31");

    expect($bg['profit']->equals($er['net_profit']))->toBeTrue();
});

it('agrupa las cuentas del balance por su grupo de segundo nivel', function () {
    postSampleYear($this->engine, $this->year);

    $bg = $this->statements->balanceSheet("{$this->year}-12-31");
    $groups = collect($bg['assets'])->pluck('code');

    expect($groups)->toContain('1.1')   // Activo corriente
        ->and($groups)->toContain('1.2'); // Activo no corriente
});

it('cuadra el balance en cualquier fecha intermedia', function () {
    postSampleYear($this->engine, $this->year);

    foreach (['01-31', '02-28', '03-31', '06-30'] as $day) {
        $bg = $this->statements->balanceSheet("{$this->year}-{$day}");

        expect($bg['balanced'])->toBeTrue("El balance no cuadra al {$day}");
    }
});

/*
|--------------------------------------------------------------------------
| Flujo de efectivo
|--------------------------------------------------------------------------
*/

it('cuadra el flujo de efectivo con la variación real de caja', function () {
    postSampleYear($this->engine, $this->year);

    $ff = $this->cashFlow->cashFlow("{$this->year}-01-01", "{$this->year}-12-31");

    expect($ff['reconciled'])->toBeTrue()
        ->and($ff['net_change']->equals($ff['computed_change']))->toBeTrue();
});

it('clasifica los movimientos en operación, inversión y financiamiento', function () {
    postSampleYear($this->engine, $this->year);

    $ff = $this->cashFlow->cashFlow("{$this->year}-01-01", "{$this->year}-12-31");

    // Financiamiento: 200.000 de capital + 50.000 de préstamo.
    expect($ff['sections']['financing']['total']->toString())->toBe('250000.0000')
        // Inversión: 30.000 de equipo, salida.
        ->and($ff['sections']['investing']['total']->toString())->toBe('-30000.0000');

    // Operación: el resto hasta cuadrar con la variación de caja.
    $operating = $ff['sections']['operating']['total'];
    $total = $operating->plus(Money::of('250000'))->plus(Money::of('-30000'));

    expect($total->equals($ff['net_change']))->toBeTrue();
});

it('ignora los traslados internos entre cuentas de efectivo', function () {
    postSampleYear($this->engine, $this->year);

    $antes = $this->cashFlow->cashFlow("{$this->year}-01-01", "{$this->year}-12-31");

    // Mover dinero de banco a caja no cambia el efectivo total.
    $this->engine->post(JournalDraft::on("{$this->year}-05-10", 'Traslado a caja chica')
        ->debit(account('1.1.01.02')->id, '5000.00')
        ->credit(account('1.1.02.01')->id, '5000.00'));

    $despues = $this->cashFlow->cashFlow("{$this->year}-01-01", "{$this->year}-12-31");

    expect($despues['net_change']->equals($antes['net_change']))->toBeTrue()
        ->and($despues['reconciled'])->toBeTrue();
});

it('deja el flujo en cero cuando no hubo movimientos de caja', function () {
    // Compra al crédito: ni entra ni sale efectivo.
    $this->engine->post(JournalDraft::on("{$this->year}-02-10", 'Compra al crédito')
        ->debit(account('1.1.04.01')->id, '5000.00')
        ->credit(account('2.1.01.01')->id, '5000.00'));

    $ff = $this->cashFlow->cashFlow("{$this->year}-01-01", "{$this->year}-12-31");

    expect($ff['net_change']->isZero())->toBeTrue()
        ->and($ff['reconciled'])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Coherencia entre los tres estados
|--------------------------------------------------------------------------
*/

it('mantiene coherentes el balance de comprobación, el de situación y el de resultados', function () {
    postSampleYear($this->engine, $this->year);

    $tb = $this->statements->trialBalance("{$this->year}-01-01", "{$this->year}-12-31");
    $bg = $this->statements->balanceSheet("{$this->year}-12-31");
    $er = $this->statements->incomeStatement("{$this->year}-01-01", "{$this->year}-12-31");

    // El activo del balance debe salir de las mismas cuentas del balance de
    // comprobación con saldo deudor de tipo activo.
    $activoSegunTB = Money::sum(
        $tb['rows']
            ->filter(fn (StatementRow $r) => $r->type->value === 'asset')
            ->map(fn (StatementRow $r) => $r->closing)
            ->all()
    );

    expect($activoSegunTB->equals($bg['total_assets']))->toBeTrue()
        ->and($bg['profit']->equals($er['net_profit']))->toBeTrue()
        ->and($tb['balanced'])->toBeTrue()
        ->and($bg['balanced'])->toBeTrue();
});

it('aísla los estados financieros entre empresas', function () {
    postSampleYear($this->engine, $this->year);
    $propio = $this->statements->balanceSheet("{$this->year}-12-31")['total_assets'];

    $otra = accountingCompany();
    actingAsUserOf($otra, role: PermissionCatalog::ACCOUNTANT);

    $ajeno = $this->statements->balanceSheet("{$this->year}-12-31");

    expect($propio->isPositive())->toBeTrue()
        ->and($ajeno['total_assets']->isZero())->toBeTrue();
});
