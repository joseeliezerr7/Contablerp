<?php

declare(strict_types=1);

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Support\Money;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->engine = app(AccountingEngine::class);
    $this->ledger = app(LedgerQueryService::class);

    $this->clientes = account('1.1.03.01');   // deudora
    $this->proveedores = account('2.1.01.01'); // acreedora
    $this->ventas = account('4.1.01');
    $this->banco = account('1.1.02.01');
});

it('acumula el saldo de una cuenta deudora', function () {
    $this->engine->post(
        JournalDraft::on('2026-03-05', 'Venta 1')
            ->debit($this->clientes->id, '1000.00')
            ->credit($this->ventas->id, '1000.00')
    );

    $this->engine->post(
        JournalDraft::on('2026-03-10', 'Cobro')
            ->debit($this->banco->id, '400.00')
            ->credit($this->clientes->id, '400.00')
    );

    $mayor = $this->ledger->ledgerFor($this->clientes, '2026-03-01', '2026-03-31');

    expect($mayor['rows'])->toHaveCount(2)
        ->and($mayor['debit']->toString())->toBe('1000.0000')
        ->and($mayor['credit']->toString())->toBe('400.0000')
        ->and($mayor['closing']->toString())->toBe('600.0000');
});

it('muestra el saldo de una cuenta acreedora en positivo', function () {
    // Con debe menos haber, el pasivo se vería en negativo; el mayor debe
    // respetar la naturaleza de la cuenta.
    $this->engine->post(
        JournalDraft::on('2026-03-05', 'Compra')
            ->debit(account('1.1.04.01')->id, '9200.00')
            ->credit($this->proveedores->id, '9200.00')
    );

    $mayor = $this->ledger->ledgerFor($this->proveedores, '2026-03-01', '2026-03-31');

    expect($mayor['closing']->isPositive())->toBeTrue()
        ->and($mayor['closing']->toString())->toBe('9200.0000');
});

it('arrastra el saldo inicial de períodos anteriores', function () {
    $this->engine->post(
        JournalDraft::on('2026-02-10', 'Movimiento de febrero')
            ->debit($this->clientes->id, '2500.00')
            ->credit($this->ventas->id, '2500.00')
    );

    $this->engine->post(
        JournalDraft::on('2026-03-10', 'Movimiento de marzo')
            ->debit($this->clientes->id, '1500.00')
            ->credit($this->ventas->id, '1500.00')
    );

    $mayor = $this->ledger->ledgerFor($this->clientes, '2026-03-01', '2026-03-31');

    expect($mayor['opening']->toString())->toBe('2500.0000')
        ->and($mayor['rows'])->toHaveCount(1)
        ->and($mayor['closing']->toString())->toBe('4000.0000');
});

it('excluye los borradores y las partidas anuladas', function () {
    $this->engine->saveDraft(
        JournalDraft::on('2026-03-05', 'Borrador')
            ->debit($this->clientes->id, '9999.00')
            ->credit($this->ventas->id, '9999.00')
    );

    $anulada = $this->engine->post(
        JournalDraft::on('2026-03-06', 'Se anula')
            ->debit($this->clientes->id, '777.00')
            ->credit($this->ventas->id, '777.00')
    );
    $this->engine->void($anulada, 'Error de captura');

    $this->engine->post(
        JournalDraft::on('2026-03-07', 'Válida')
            ->debit($this->clientes->id, '100.00')
            ->credit($this->ventas->id, '100.00')
    );

    $mayor = $this->ledger->ledgerFor($this->clientes, '2026-03-01', '2026-03-31');

    expect($mayor['rows'])->toHaveCount(1)
        ->and($mayor['closing']->toString())->toBe('100.0000');
});

it('ordena los movimientos por fecha y folio', function () {
    $this->engine->post(
        JournalDraft::on('2026-03-20', 'Tercera')
            ->debit($this->clientes->id, '30.00')
            ->credit($this->ventas->id, '30.00')
    );

    $this->engine->post(
        JournalDraft::on('2026-03-05', 'Primera')
            ->debit($this->clientes->id, '10.00')
            ->credit($this->ventas->id, '10.00')
    );

    $this->engine->post(
        JournalDraft::on('2026-03-12', 'Segunda')
            ->debit($this->clientes->id, '20.00')
            ->credit($this->ventas->id, '20.00')
    );

    $mayor = $this->ledger->ledgerFor($this->clientes, '2026-03-01', '2026-03-31');

    expect($mayor['rows']->pluck('concept')->all())
        ->toBe(['Primera', 'Segunda', 'Tercera']);
});

it('filtra por sucursal', function () {
    $sucursal = $this->company->branches()->first();

    $this->engine->post(
        JournalDraft::on('2026-03-05', 'Con sucursal')
            ->inBranch($sucursal->id)
            ->debit($this->clientes->id, '100.00')
            ->credit($this->ventas->id, '100.00')
    );

    $this->engine->post(
        JournalDraft::on('2026-03-06', 'Sin sucursal')
            ->debit($this->clientes->id, '200.00')
            ->credit($this->ventas->id, '200.00')
    );

    $conFiltro = $this->ledger->ledgerFor($this->clientes, '2026-03-01', '2026-03-31', $sucursal->id);
    $sinFiltro = $this->ledger->ledgerFor($this->clientes, '2026-03-01', '2026-03-31');

    expect($conFiltro['rows'])->toHaveCount(1)
        ->and($sinFiltro['rows'])->toHaveCount(2);
});

it('devuelve cero en una cuenta sin movimientos', function () {
    $mayor = $this->ledger->ledgerFor($this->clientes, '2026-03-01', '2026-03-31');

    expect($mayor['rows'])->toBeEmpty()
        ->and($mayor['opening']->isZero())->toBeTrue()
        ->and($mayor['closing']->isZero())->toBeTrue();
});

it('cuadra el mayor de todas las cuentas contra el diario', function () {
    $this->engine->post(
        JournalDraft::on('2026-03-05', 'Venta con impuesto')
            ->debit($this->clientes->id, '11500.00')
            ->credit($this->ventas->id, '10000.00')
            ->credit(account('2.1.02.01')->id, '1500.00')
    );

    $cuentas = $this->ledger->accountsWithActivity('2026-03-01', '2026-03-31');

    $totalDebe = Money::zero();
    $totalHaber = Money::zero();

    foreach ($cuentas as $cuenta) {
        $mayor = $this->ledger->ledgerFor($cuenta, '2026-03-01', '2026-03-31');
        $totalDebe = $totalDebe->plus($mayor['debit']);
        $totalHaber = $totalHaber->plus($mayor['credit']);
    }

    expect($cuentas)->toHaveCount(3)
        ->and($totalDebe->equals($totalHaber))->toBeTrue();
});
