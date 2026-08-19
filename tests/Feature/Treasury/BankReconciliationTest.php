<?php

declare(strict_types=1);

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Models\JournalEntryLine;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Treasury\Exceptions\TreasuryException;
use App\Domains\Treasury\Models\BankAccount;
use App\Domains\Treasury\Models\BankReconciliation;
use App\Domains\Treasury\Services\BankAccountService;
use App\Domains\Treasury\Services\BankReconciliationService;
use App\Support\Money;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();

    $this->banks = app(BankAccountService::class);
    $this->reconciliations = app(BankReconciliationService::class);
    $this->engine = app(AccountingEngine::class);

    $this->bankAccount = $this->banks->create([
        'account_id' => account('1.1.02.01')->id,
        'bank_name' => 'Banco Atlántida',
        'number' => '01-234-567890',
        'alias' => 'Cuenta operativa',
        'next_check_number' => 1001,
    ]);

    $this->capital = account('3.1.01');
    $this->gasto = account('6.3.02');
});

/**
 * Entrada de dinero al banco.
 */
function bankIn(object $ctx, string $amount, string $date, string $concept = 'Depósito'): void
{
    $ctx->engine->post(
        JournalDraft::on($date, $concept)
            ->debit(account('1.1.02.01')->id, $amount)
            ->credit($ctx->capital->id, $amount)
    );
}

/**
 * Salida de dinero del banco.
 */
function bankOut(object $ctx, string $amount, string $date, string $concept = 'Pago'): void
{
    $ctx->engine->post(
        JournalDraft::on($date, $concept)
            ->debit($ctx->gasto->id, $amount)
            ->credit(account('1.1.02.01')->id, $amount)
    );
}

/*
|--------------------------------------------------------------------------
| Cuenta bancaria
|--------------------------------------------------------------------------
*/

it('lee el saldo del libro y no de un campo propio', function () {
    bankIn($this, '50000.00', '2026-03-01');
    bankOut($this, '12000.00', '2026-03-05');

    expect($this->banks->bookBalance($this->bankAccount, '2026-03-31')->toString())
        ->toBe('38000.0000')
        // A una fecha anterior, el saldo es el de entonces.
        ->and($this->banks->bookBalance($this->bankAccount, '2026-03-03')->toString())
        ->toBe('50000.0000');
});

it('rechaza una cuenta contable que no es de efectivo', function () {
    expect(fn () => $this->banks->create([
        'account_id' => account('6.1.01')->id,
        'bank_name' => 'Banco X',
        'number' => '999',
    ]))->toThrow(TreasuryException::class, 'no está marcada como efectivo');
});

it('rechaza dos cuentas bancarias sobre la misma cuenta contable', function () {
    expect(fn () => $this->banks->create([
        'account_id' => account('1.1.02.01')->id,
        'bank_name' => 'Otro Banco',
        'number' => '888',
    ]))->toThrow(TreasuryException::class, 'ya pertenece a otra cuenta bancaria');
});

/*
|--------------------------------------------------------------------------
| La identidad de la conciliación
|--------------------------------------------------------------------------
*/

it('cuadra cuando el extracto coincide con todo el libro', function () {
    bankIn($this, '50000.00', '2026-03-01');
    bankOut($this, '12000.00', '2026-03-05');

    $conciliacion = $this->reconciliations->open($this->bankAccount, '2026-03-31', Money::of('38000.00'));
    $conciliacion = $this->reconciliations->markAll($conciliacion);

    expect($conciliacion->bookBalance()->toString())->toBe('38000.0000')
        ->and($conciliacion->depositsInTransit()->isZero())->toBeTrue()
        ->and($conciliacion->outstandingChecks()->isZero())->toBeTrue()
        ->and($conciliacion->isBalanced())->toBeTrue();
});

it('explica la diferencia con un cheque que el banco no ha pagado', function () {
    bankIn($this, '50000.00', '2026-03-01');
    bankOut($this, '12000.00', '2026-03-28', 'Cheque a proveedor');

    // El banco todavía no cobró el cheque: su extracto muestra 50 000.
    $conciliacion = $this->reconciliations->open($this->bankAccount, '2026-03-31', Money::of('50000.00'));

    $deposito = $this->reconciliations->items($conciliacion)->firstWhere('debit', '50000.0000');
    $conciliacion = $this->reconciliations->mark($conciliacion, $deposito->id, '2026-03-02');

    expect($conciliacion->bookBalance()->toString())->toBe('38000.0000')
        ->and($conciliacion->outstandingChecks()->toString())->toBe('12000.0000')
        ->and($conciliacion->depositsInTransit()->isZero())->toBeTrue()
        // 50 000 + 0 − 12 000 = 38 000
        ->and($conciliacion->isBalanced())->toBeTrue();
});

it('explica la diferencia con un depósito en tránsito', function () {
    bankIn($this, '50000.00', '2026-03-01');
    bankIn($this, '7500.00', '2026-03-31', 'Depósito del último día');

    // El banco todavía no acreditó el segundo depósito.
    $conciliacion = $this->reconciliations->open($this->bankAccount, '2026-03-31', Money::of('50000.00'));

    $primero = $this->reconciliations->items($conciliacion)->firstWhere('debit', '50000.0000');
    $conciliacion = $this->reconciliations->mark($conciliacion, $primero->id);

    expect($conciliacion->bookBalance()->toString())->toBe('57500.0000')
        ->and($conciliacion->depositsInTransit()->toString())->toBe('7500.0000')
        // 50 000 + 7 500 − 0 = 57 500
        ->and($conciliacion->isBalanced())->toBeTrue();
});

it('deja la diferencia a la vista cuando algo no cuadra', function () {
    bankIn($this, '50000.00', '2026-03-01');

    // El extracto trae una comisión de 350 que no está en el libro.
    $conciliacion = $this->reconciliations->open($this->bankAccount, '2026-03-31', Money::of('49650.00'));
    $conciliacion = $this->reconciliations->markAll($conciliacion);

    expect($conciliacion->differenceAmount()->toString())->toBe('-350.0000')
        ->and($conciliacion->isBalanced())->toBeFalse();
});

it('cuadra al registrar la comisión que faltaba', function () {
    bankIn($this, '50000.00', '2026-03-01');

    $conciliacion = $this->reconciliations->open($this->bankAccount, '2026-03-31', Money::of('49650.00'));
    $conciliacion = $this->reconciliations->markAll($conciliacion);

    expect($conciliacion->isBalanced())->toBeFalse();

    // Se registra la comisión que el banco cobró y se vuelve a marcar.
    bankOut($this, '350.00', '2026-03-30', 'Comisión bancaria');
    $conciliacion = $this->reconciliations->markAll($conciliacion);

    expect($conciliacion->isBalanced())->toBeTrue()
        ->and($conciliacion->bookBalance()->toString())->toBe('49650.0000');
});

/*
|--------------------------------------------------------------------------
| Cierre
|--------------------------------------------------------------------------
*/

it('rechaza cerrar una conciliación que no cuadra', function () {
    bankIn($this, '50000.00', '2026-03-01');

    $conciliacion = $this->reconciliations->open($this->bankAccount, '2026-03-31', Money::of('49650.00'));
    $conciliacion = $this->reconciliations->markAll($conciliacion);

    expect(fn () => $this->reconciliations->close($conciliacion))
        ->toThrow(TreasuryException::class, 'no cuadra');
});

it('cierra la conciliación que cuadra y congela sus importes', function () {
    bankIn($this, '50000.00', '2026-03-01');

    $conciliacion = $this->reconciliations->open($this->bankAccount, '2026-03-31', Money::of('50000.00'));
    $conciliacion = $this->reconciliations->markAll($conciliacion);
    $conciliacion = $this->reconciliations->close($conciliacion);

    expect($conciliacion->isClosed())->toBeTrue()
        ->and($conciliacion->closed_at)->not->toBeNull()
        ->and($conciliacion->bookBalance()->toString())->toBe('50000.0000');
});

it('no deja tocar una conciliación cerrada', function () {
    bankIn($this, '50000.00', '2026-03-01');

    $conciliacion = $this->reconciliations->open($this->bankAccount, '2026-03-31', Money::of('50000.00'));
    $items = $this->reconciliations->items($conciliacion);
    $conciliacion = $this->reconciliations->markAll($conciliacion);
    $conciliacion = $this->reconciliations->close($conciliacion);

    expect(fn () => $this->reconciliations->unmark($conciliacion, $items->first()->id))
        ->toThrow(TreasuryException::class, 'ya está cerrada');
});

/*
|--------------------------------------------------------------------------
| Dos meses seguidos: el error que se paga en el segundo
|--------------------------------------------------------------------------
*/

it('no vuelve a contar en febrero lo que ya se concilió en enero', function () {
    bankIn($this, '30000.00', '2026-01-10');
    bankOut($this, '5000.00', '2026-01-20');

    $enero = $this->reconciliations->open($this->bankAccount, '2026-01-31', Money::of('25000.00'));
    $enero = $this->reconciliations->markAll($enero);
    $this->reconciliations->close($enero);

    // Febrero: entra y sale más dinero, y el extracto es acumulativo.
    bankIn($this, '9000.00', '2026-02-05');
    bankOut($this, '4000.00', '2026-02-25');

    $febrero = $this->reconciliations->open($this->bankAccount, '2026-02-28', Money::of('30000.00'));
    $febrero = $this->reconciliations->markAll($febrero);

    expect($febrero->bookBalance()->toString())->toBe('30000.0000')
        // Nada de enero reaparece como pendiente.
        ->and($febrero->depositsInTransit()->isZero())->toBeTrue()
        ->and($febrero->outstandingChecks()->isZero())->toBeTrue()
        ->and($febrero->isBalanced())->toBeTrue();
});

it('arrastra a febrero el cheque que enero dejó pendiente', function () {
    bankIn($this, '30000.00', '2026-01-10');
    bankOut($this, '5000.00', '2026-01-28', 'Cheque girado a fin de mes');

    // Enero cierra con el cheque pendiente: extracto 30 000.
    $enero = $this->reconciliations->open($this->bankAccount, '2026-01-31', Money::of('30000.00'));
    $deposito = $this->reconciliations->items($enero)->firstWhere('debit', '30000.0000');
    $enero = $this->reconciliations->mark($enero, $deposito->id);
    $this->reconciliations->close($enero);

    expect($enero->outstandingChecks()->toString())->toBe('5000.0000');

    // En febrero el banco cobra el cheque. El extracto baja a 25 000.
    $febrero = $this->reconciliations->open($this->bankAccount, '2026-02-28', Money::of('25000.00'));

    $cheque = $this->reconciliations->items($febrero)->firstWhere('credit', '5000.0000');

    expect($cheque)->not->toBeNull('El cheque de enero debe seguir disponible para conciliar');

    $febrero = $this->reconciliations->mark($febrero, $cheque->id, '2026-02-03');

    expect($febrero->bookBalance()->toString())->toBe('25000.0000')
        ->and($febrero->isBalanced())->toBeTrue();
});

it('impide conciliar dos veces la misma partida', function () {
    bankIn($this, '30000.00', '2026-01-10');

    $enero = $this->reconciliations->open($this->bankAccount, '2026-01-31', Money::of('30000.00'));
    $linea = $this->reconciliations->items($enero)->first();
    $enero = $this->reconciliations->markAll($enero);
    $this->reconciliations->close($enero);

    $febrero = $this->reconciliations->open($this->bankAccount, '2026-02-28', Money::of('30000.00'));

    expect(fn () => $this->reconciliations->mark($febrero, $linea->id))
        ->toThrow(TreasuryException::class, 'ya fue conciliada');
});

/*
|--------------------------------------------------------------------------
| Guardas y aislamiento
|--------------------------------------------------------------------------
*/

it('rechaza conciliar una partida de otra cuenta', function () {
    bankIn($this, '30000.00', '2026-03-01');

    $conciliacion = $this->reconciliations->open($this->bankAccount, '2026-03-31', Money::of('30000.00'));

    $lineaDeCapital = JournalEntryLine::query()
        ->where('account_id', $this->capital->id)->firstOrFail();

    expect(fn () => $this->reconciliations->mark($conciliacion, $lineaDeCapital->id))
        ->toThrow(TreasuryException::class, 'no pertenece a la cuenta bancaria');
});

it('no ofrece partidas posteriores a la fecha de corte', function () {
    bankIn($this, '30000.00', '2026-03-01');
    bankIn($this, '1000.00', '2026-04-02', 'Ya es abril');

    $conciliacion = $this->reconciliations->open($this->bankAccount, '2026-03-31', Money::of('30000.00'));

    expect($this->reconciliations->items($conciliacion))->toHaveCount(1);
});

it('aísla la tesorería entre empresas', function () {
    bankIn($this, '30000.00', '2026-03-01');
    $this->reconciliations->open($this->bankAccount, '2026-03-31', Money::of('30000.00'));

    $otra = accountingCompany();
    actingAsUserOf($otra, role: PermissionCatalog::ACCOUNTANT);

    expect(BankAccount::query()->count())->toBe(0)
        ->and(BankReconciliation::query()->count())->toBe(0)
        ->and(acrossCompanies(fn () => BankAccount::acrossCompanies()->count()))->toBe(1);
});
