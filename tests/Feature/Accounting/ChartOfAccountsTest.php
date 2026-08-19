<?php

declare(strict_types=1);

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Enums\AccountMappingKey;
use App\Domains\Accounting\Enums\AccountNature;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Exceptions\InvalidAccountException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\AccountMapping;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Accounting\Services\ChartOfAccountsService;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->service = app(ChartOfAccountsService::class);
});

it('siembra el catálogo hondureño al crear la empresa', function () {
    expect(Account::query()->count())->toBeGreaterThan(80)
        ->and(account('1')->name)->toBe('ACTIVO')
        ->and(account('4.1.01')->name)->toBe('Ventas');
});

it('calcula nivel y ruta de la jerarquía', function () {
    $hoja = account('1.1.03.01');

    expect($hoja->level)->toBe(4)
        ->and($hoja->path)->toBe('1/1.1/1.1.03/1.1.03.01')
        ->and($hoja->parent->code)->toBe('1.1.03');
});

it('marca como no imputables las cuentas con subcuentas', function () {
    expect(account('1')->is_postable)->toBeFalse()
        ->and(account('1.1.03')->is_postable)->toBeFalse()
        ->and(account('1.1.03.01')->is_postable)->toBeTrue();
});

it('respeta la naturaleza invertida de las contra-cuentas', function () {
    // Tipo activo pero saldo acreedor: si se dedujera del tipo, el balance
    // mostraría la depreciación sumando en vez de restando.
    expect(account('1.2.02.01')->type)->toBe(AccountType::Asset)
        ->and(account('1.2.02.01')->nature)->toBe(AccountNature::Credit)
        ->and(account('4.1.03')->type)->toBe(AccountType::Income)
        ->and(account('4.1.03')->nature)->toBe(AccountNature::Debit)
        ->and(account('5.1.02')->nature)->toBe(AccountNature::Credit);
});

it('configura todas las cuentas por módulo', function () {
    $claves = AccountMapping::query()->pluck('key')->all();

    foreach (AccountMappingKey::cases() as $key) {
        expect($claves)->toContain($key->value);
    }
});

it('crea una subcuenta y convierte al padre en cuenta de agrupación', function () {
    $padre = account('1.1.01.02'); // Caja Chica, hoja imputable

    $this->service->create([
        'parent_id' => $padre->id,
        'code' => '1.1.01.02.01',
        'name' => 'Caja Chica Recepción',
        'type' => AccountType::Asset->value,
    ]);

    expect($padre->refresh()->is_postable)->toBeFalse()
        ->and(account('1.1.01.02.01')->level)->toBe(5)
        ->and(account('1.1.01.02.01')->path)->toBe('1/1.1/1.1.01/1.1.01.02/1.1.01.02.01')
        ->and(account('1.1.01.02.01')->nature)->toBe(AccountNature::Debit);
});

it('rechaza una subcuenta cuyo código no cuelga del padre', function () {
    $padre = account('1.1.01.02');

    expect(fn () => $this->service->create([
        'parent_id' => $padre->id,
        'code' => '9.9.99',
        'name' => 'Código inconsistente',
        'type' => AccountType::Asset->value,
    ]))->toThrow(InvalidAccountException::class, 'no cuelga de');
});

it('no convierte en agrupación una cuenta que ya tiene movimientos', function () {
    $cuenta = account('1.1.01.02');

    app(AccountingEngine::class)->post(
        JournalDraft::on('2026-03-15', 'Movimiento previo')
            ->debit($cuenta->id, '100.00')
            ->credit(account('4.1.01')->id, '100.00')
    );

    expect(fn () => $this->service->create([
        'parent_id' => $cuenta->id,
        'code' => $cuenta->code.'.01',
        'name' => 'Subcuenta tardía',
        'type' => AccountType::Asset->value,
    ]))->toThrow(InvalidAccountException::class, 'ya tiene movimientos');
});

it('no elimina una cuenta del sistema', function () {
    expect(fn () => $this->service->delete(account('4.1.01')))
        ->toThrow(InvalidAccountException::class, 'no puede eliminarse');
});

it('no elimina una cuenta con subcuentas', function () {
    expect(fn () => $this->service->delete(account('1.1.03')))
        ->toThrow(InvalidAccountException::class, 'tiene subcuentas');
});

it('no elimina una cuenta con movimientos', function () {
    $cuenta = account('1.1.01.02');

    app(AccountingEngine::class)->post(
        JournalDraft::on('2026-03-15', 'Movimiento')
            ->debit($cuenta->id, '100.00')
            ->credit(account('4.1.01')->id, '100.00')
    );

    expect(fn () => $this->service->delete($cuenta->refresh()))
        ->toThrow(InvalidAccountException::class, 'tiene movimientos');
});

it('devuelve la cuenta al estado imputable al borrar su última subcuenta', function () {
    $padre = account('1.1.01.02');

    $hija = $this->service->create([
        'parent_id' => $padre->id,
        'code' => '1.1.01.02.01',
        'name' => 'Temporal',
        'type' => AccountType::Asset->value,
    ]);

    expect($padre->refresh()->is_postable)->toBeFalse();

    $this->service->delete($hija);

    expect($padre->refresh()->is_postable)->toBeTrue();
});

it('no cambia el tipo de una cuenta con movimientos', function () {
    $cuenta = account('1.1.01.02');

    app(AccountingEngine::class)->post(
        JournalDraft::on('2026-03-15', 'Movimiento')
            ->debit($cuenta->id, '100.00')
            ->credit(account('4.1.01')->id, '100.00')
    );

    expect(fn () => $this->service->update($cuenta->refresh(), ['type' => AccountType::Expense->value]))
        ->toThrow(InvalidAccountException::class, 'no puede eliminarse ni cambiar de tipo');
});

it('mantiene las rutas de los descendientes al cambiar un código', function () {
    $this->service->update(account('1.1.01'), ['code' => '1.1.09']);

    expect(account('1.1.09')->path)->toBe('1/1.1/1.1.09')
        ->and(account('1.1.01.01')->path)->toBe('1/1.1/1.1.09/1.1.01.01');
});

it('aísla el plan de cuentas entre empresas', function () {
    $propias = Account::query()->count();
    $otra = accountingCompany();

    expect(Account::query()->count())->toBe($propias)
        ->and(acrossCompanies(fn () => Account::acrossCompanies()->count()))->toBe($propias * 2);
});
