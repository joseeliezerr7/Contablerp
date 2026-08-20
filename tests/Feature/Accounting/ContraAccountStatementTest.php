<?php

declare(strict_types=1);

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Accounting\Services\FinancialStatementService;

/**
 * Cuentas de contrapartida en los estados financieros.
 *
 * Una cuenta de contrapartida es la que vive en un bloque pero se mueve al
 * revés que él: la depreciación acumulada es una cuenta de **activo** con
 * naturaleza acreedora, y los descuentos sobre ventas una cuenta de **ingreso**
 * con naturaleza deudora. En los dos casos el importe tiene que **restar** de
 * su bloque.
 *
 * Estas pruebas existen porque el fallo estuvo latente mucho tiempo: hasta que
 * la depreciación registró su primera corrida, ninguna cuenta de contrapartida
 * había tenido saldo,
 * y el balance descuadraba exactamente por el doble del importe —la firma de un
 * signo invertido—.
 */
beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();

    $this->engine = app(AccountingEngine::class);
    $this->statements = app(FinancialStatementService::class);
});

it('resta la depreciación acumulada del activo', function () {
    // Un equipo comprado y un mes de depreciación.
    $this->engine->post(
        JournalDraft::on('2026-03-01', 'Compra de equipo')
            ->debit(account('1.2.01.04')->id, '36000.00')
            ->credit(account('3.1.01')->id, '36000.00')
    );

    $this->engine->post(
        JournalDraft::on('2026-03-31', 'Depreciación')
            ->debit(account('6.1.06')->id, '1000.00')
            ->credit(account('1.2.02.03')->id, '1000.00')
    );

    $balance = $this->statements->balanceSheet('2026-12-31');

    // 36 000 − 1 000 = 35 000, no 37 000.
    expect($balance['total_assets']->toString())->toBe('35000.0000')
        ->and($balance['balanced'])->toBeTrue();
});

it('cuadra el balance general con depreciación acumulada', function () {
    $this->engine->post(
        JournalDraft::on('2026-03-01', 'Aporte de capital')
            ->debit(account('1.1.02.01')->id, '100000.00')
            ->credit(account('3.1.01')->id, '100000.00')
    );

    $this->engine->post(
        JournalDraft::on('2026-03-02', 'Compra de vehículo')
            ->debit(account('1.2.01.05')->id, '60000.00')
            ->credit(account('1.1.02.01')->id, '60000.00')
    );

    $this->engine->post(
        JournalDraft::on('2026-03-31', 'Depreciación del mes')
            ->debit(account('6.1.06')->id, '1000.00')
            ->credit(account('1.2.02.04')->id, '1000.00')
    );

    $balance = $this->statements->balanceSheet('2026-12-31');

    expect($balance['balanced'])->toBeTrue(
        "Descuadre de {$balance['difference']->format()}."
    )
        // Banco 40 000 + vehículo 60 000 − depreciación 1 000.
        ->and($balance['total_assets']->toString())->toBe('99000.0000');
});

it('resta los descuentos sobre ventas del ingreso', function () {
    $this->engine->post(
        JournalDraft::on('2026-03-05', 'Venta con descuento')
            ->debit(account('1.1.03.01')->id, '9000.00')
            ->debit(account('4.1.04')->id, '1000.00')
            ->credit(account('4.1.01')->id, '10000.00')
    );

    $resultados = $this->statements->incomeStatement('2026-01-01', '2026-12-31');

    // 10 000 de venta menos 1 000 de descuento.
    expect($resultados['total_income']->toString())->toBe('9000.0000')
        ->and($resultados['net_profit']->toString())->toBe('9000.0000');
});

it('deja el balance cuadrado con descuentos sobre ventas', function () {
    $this->engine->post(
        JournalDraft::on('2026-03-05', 'Venta con descuento')
            ->debit(account('1.1.03.01')->id, '9000.00')
            ->debit(account('4.1.04')->id, '1000.00')
            ->credit(account('4.1.01')->id, '10000.00')
    );

    $balance = $this->statements->balanceSheet('2026-12-31');

    expect($balance['balanced'])->toBeTrue(
        "Descuadre de {$balance['difference']->format()}."
    )
        ->and($balance['profit']->toString())->toBe('9000.0000');
});

it('no altera las cuentas que sí van en el sentido de su bloque', function () {
    $this->engine->post(
        JournalDraft::on('2026-03-01', 'Compra al crédito')
            ->debit(account('1.1.04.01')->id, '5000.00')
            ->credit(account('2.1.01.01')->id, '5000.00')
    );

    $balance = $this->statements->balanceSheet('2026-12-31');

    // Un activo normal suma y un pasivo normal suma en su bloque.
    expect($balance['total_assets']->toString())->toBe('5000.0000')
        ->and($balance['total_liabilities']->toString())->toBe('5000.0000')
        ->and($balance['balanced'])->toBeTrue();
});
