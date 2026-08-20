<?php

declare(strict_types=1);

use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Treasury\Services\BankAccountService;
use App\Domains\Treasury\Services\BankReconciliationService;
use App\Support\Money;

/**
 * Qué alcanza cada rol en tesorería.
 *
 * Estas pruebas existen por una lección concreta: en su momento el contador se
 * quedó fuera del formulario de ajustes porque los permisos se repartieron
 * pensando en una empresa grande, y ninguna prueba lo detectó porque ninguna
 * probaba las pantallas. Los servicios pueden estar impecables y el módulo ser
 * inalcanzable.
 */
beforeEach(function () {
    $this->company = accountingCompany();
});

/**
 * Deja una cuenta bancaria y una conciliación listas, como contador.
 *
 * @return array{0: int, 1: int}
 */
function seedTreasuryFixture(object $ctx): array
{
    actingAsUserOf($ctx->company, role: PermissionCatalog::ACCOUNTANT);

    $banks = app(BankAccountService::class);
    $reconciliations = app(BankReconciliationService::class);

    $bankAccount = $banks->create([
        'account_id' => account('1.1.02.01')->id,
        'bank_name' => 'Banco Atlántida',
        'number' => '01-234-567890',
        'next_check_number' => 1001,
    ]);

    $reconciliation = $reconciliations->open($bankAccount, now()->endOfMonth(), Money::zero());

    return [$bankAccount->id, $reconciliation->id];
}

/*
|--------------------------------------------------------------------------
| Contador: hace de todo, porque en una empresa pequeña es el único
|--------------------------------------------------------------------------
*/

it('deja al contador entrar a las cuatro pantallas de tesorería', function () {
    [, $reconciliationId] = seedTreasuryFixture($this);

    $this->get(route('treasury.banks.index'))->assertOk();
    $this->get(route('treasury.checks.index'))->assertOk();
    $this->get(route('treasury.reconciliations.index'))->assertOk();
    $this->get(route('treasury.reconciliations.show', $reconciliationId))->assertOk();
    $this->get(route('treasury.cash.index'))->assertOk();
});

it('deja al contador administrar cuentas, conciliar y operar la caja', function () {
    seedTreasuryFixture($this);

    $user = auth()->user();

    expect($user->can('treasury.banks.manage'))->toBeTrue()
        ->and($user->can('treasury.reconciliation.manage'))->toBeTrue()
        ->and($user->can('treasury.reconciliation.close'))->toBeTrue()
        // El botón de abrir caja depende de este permiso.
        ->and($user->can('treasury.cash.operate'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Cajero: opera su caja y nada más
|--------------------------------------------------------------------------
*/

it('deja al cajero entrar a caja pero no a bancos ni a conciliación', function () {
    [, $reconciliationId] = seedTreasuryFixture($this);

    actingAsUserOf($this->company, role: PermissionCatalog::CASHIER);

    $this->get(route('treasury.cash.index'))->assertOk();
    $this->get(route('treasury.banks.index'))->assertForbidden();
    $this->get(route('treasury.reconciliations.index'))->assertForbidden();
    $this->get(route('treasury.reconciliations.show', $reconciliationId))->assertForbidden();
});

it('deja al cajero abrir y cerrar su caja', function () {
    seedTreasuryFixture($this);

    actingAsUserOf($this->company, role: PermissionCatalog::CASHIER);

    expect(auth()->user()->can('treasury.cash.operate'))->toBeTrue()
        ->and(auth()->user()->can('treasury.reconciliation.manage'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Gerente y auditor: miran, no tocan
|--------------------------------------------------------------------------
*/

it('deja al gerente ver la tesorería y aprobar la conciliación sin armarla', function () {
    [, $reconciliationId] = seedTreasuryFixture($this);

    actingAsUserOf($this->company, role: PermissionCatalog::MANAGER);

    $this->get(route('treasury.reconciliations.show', $reconciliationId))->assertOk();

    expect(auth()->user()->can('treasury.reconciliation.close'))->toBeTrue()
        ->and(auth()->user()->can('treasury.reconciliation.manage'))->toBeFalse()
        ->and(auth()->user()->can('treasury.banks.manage'))->toBeFalse();
});

it('deja al auditor mirar sin poder cambiar nada', function () {
    [, $reconciliationId] = seedTreasuryFixture($this);

    actingAsUserOf($this->company, role: PermissionCatalog::AUDITOR);

    $this->get(route('treasury.banks.index'))->assertOk();
    $this->get(route('treasury.reconciliations.show', $reconciliationId))->assertOk();

    expect(auth()->user()->can('treasury.banks.manage'))->toBeFalse()
        ->and(auth()->user()->can('treasury.reconciliation.close'))->toBeFalse()
        ->and(auth()->user()->can('treasury.cash.operate'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Quien no tiene nada que hacer aquí
|--------------------------------------------------------------------------
*/

it('deja al vendedor fuera de toda la tesorería', function () {
    seedTreasuryFixture($this);

    actingAsUserOf($this->company, role: PermissionCatalog::SALESPERSON);

    $this->get(route('treasury.banks.index'))->assertForbidden();
    $this->get(route('treasury.checks.index'))->assertForbidden();
    $this->get(route('treasury.reconciliations.index'))->assertForbidden();
    $this->get(route('treasury.cash.index'))->assertForbidden();
});

it('no deja abrir la conciliación de otra empresa', function () {
    [, $reconciliationId] = seedTreasuryFixture($this);

    $otra = accountingCompany();
    actingAsUserOf($otra, role: PermissionCatalog::ACCOUNTANT);

    // El filtro de empresa la hace invisible: no existe para este usuario.
    $this->get(route('treasury.reconciliations.show', $reconciliationId))->assertNotFound();
});
