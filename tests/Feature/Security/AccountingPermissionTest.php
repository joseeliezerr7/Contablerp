<?php

declare(strict_types=1);

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Identity\Services\RoleProvisioner;
use App\Livewire\Accounting\AccountIndex;
use App\Livewire\Accounting\JournalIndex;
use App\Livewire\Accounting\PeriodIndex;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

it('siembra los siete roles en cada empresa', function () {
    $company = accountingCompany();

    $roles = Role::query()
        ->where('company_id', $company->id)
        ->pluck('name')
        ->all();

    foreach (PermissionCatalog::roleNames() as $name) {
        expect($roles)->toContain($name);
    }
});

it('permite al contador contabilizar, anular y revertir', function () {
    $company = accountingCompany();
    $user = actingAsUserOf($company, role: PermissionCatalog::ACCOUNTANT);

    expect($user->can('accounting.journal.post'))->toBeTrue()
        ->and($user->can('accounting.journal.void'))->toBeTrue()
        ->and($user->can('accounting.journal.reverse'))->toBeTrue()
        ->and($user->can('accounting.periods.close'))->toBeTrue();
});

it('deja al auditor solo en lectura', function () {
    $company = accountingCompany();
    $user = actingAsUserOf($company, role: PermissionCatalog::AUDITOR);

    expect($user->can('accounting.journal.view'))->toBeTrue()
        ->and($user->can('audit.view'))->toBeTrue()
        ->and($user->can('accounting.journal.create'))->toBeFalse()
        ->and($user->can('accounting.journal.post'))->toBeFalse()
        ->and($user->can('accounting.journal.void'))->toBeFalse()
        ->and($user->can('accounting.periods.close'))->toBeFalse();
});

it('impide al gerente capturar contabilidad', function () {
    $company = accountingCompany();
    $user = actingAsUserOf($company, role: PermissionCatalog::MANAGER);

    expect($user->can('accounting.journal.view'))->toBeTrue()
        ->and($user->can('accounting.journal.create'))->toBeFalse()
        ->and($user->can('accounting.accounts.update'))->toBeFalse();
});

it('deja al vendedor fuera del módulo contable', function () {
    $company = accountingCompany();
    $user = actingAsUserOf($company, role: PermissionCatalog::SALESPERSON);

    expect($user->can('accounting.journal.view'))->toBeFalse()
        ->and($user->can('accounting.ledger.view'))->toBeFalse()
        ->and($user->can('accounting.accounts.view'))->toBeFalse();
});

// Livewire convierte AuthorizationException en una respuesta 403 en vez de
// dejarla propagar, así que se afirma sobre el estado.

it('bloquea el libro diario a quien no tiene permiso', function () {
    $company = accountingCompany();
    actingAsUserOf($company, role: PermissionCatalog::SALESPERSON);

    Livewire::test(JournalIndex::class)->assertForbidden();
});

it('bloquea el plan de cuentas a quien no tiene permiso', function () {
    $company = accountingCompany();
    actingAsUserOf($company, role: PermissionCatalog::CASHIER);

    Livewire::test(AccountIndex::class)->assertForbidden();
});

it('impide al auditor anular una partida', function () {
    $company = accountingCompany();
    actingAsUserOf($company, role: PermissionCatalog::ACCOUNTANT);

    $entry = app(AccountingEngine::class)->post(
        JournalDraft::on(now()->format('Y').'-03-15', 'Partida')
            ->debit(account('1.1.03.01')->id, '100.00')
            ->credit(account('4.1.01')->id, '100.00')
    );

    actingAsUserOf($company, role: PermissionCatalog::AUDITOR);

    Livewire::test(JournalIndex::class)
        ->call('confirmVoid', $entry->id)
        ->assertForbidden();

    expect($entry->refresh()->isPosted())->toBeTrue();
});

it('impide al auditor cerrar un período', function () {
    $company = accountingCompany();
    actingAsUserOf($company, role: PermissionCatalog::AUDITOR);

    $enero = periodFor(now()->format('Y').'-01-15');

    Livewire::test(PeriodIndex::class)
        ->call('close', $enero->id)
        ->assertForbidden();

    expect($enero->refresh()->status->acceptsPostings())->toBeTrue();
});

it('mantiene los roles separados por empresa', function () {
    $companyA = accountingCompany();
    $companyB = accountingCompany();

    $user = actingAsUserOf($companyA, role: PermissionCatalog::ACCOUNTANT);
    $user->companies()->attach($companyB->id, ['branch_id' => null]);
    app(RoleProvisioner::class)
        ->assign($user, $companyB, PermissionCatalog::SALESPERSON);

    // Contador en A…
    app(PermissionRegistrar::class)->setPermissionsTeamId($companyA->id);
    $user->unsetRelation('roles')->unsetRelation('permissions');
    expect($user->can('accounting.journal.post'))->toBeTrue();

    // …y solo Vendedor en B.
    app(PermissionRegistrar::class)->setPermissionsTeamId($companyB->id);
    $user->unsetRelation('roles')->unsetRelation('permissions');
    expect($user->can('accounting.journal.post'))->toBeFalse();
});

it('no deja tocar cuentas de otra empresa aunque haya permiso', function () {
    $companyA = accountingCompany();
    $companyB = accountingCompany();

    $cuentaDeB = acrossCompanies(fn () => Account::acrossCompanies()
        ->where('company_id', $companyB->id)->where('code', '4.1.01')->firstOrFail());

    actingAsUserOf($companyA, role: PermissionCatalog::ACCOUNTANT);

    expect(fn () => Livewire::test(AccountIndex::class)->call('edit', $cuentaDeB->id))
        ->toThrow(ModelNotFoundException::class);
});

it('no deja anular partidas de otra empresa', function () {
    $companyA = accountingCompany();
    $companyB = accountingCompany();

    actingAsUserOf($companyB, role: PermissionCatalog::ACCOUNTANT);
    $entryDeB = app(AccountingEngine::class)->post(
        JournalDraft::on(now()->format('Y').'-03-15', 'De la empresa B')
            ->debit(account('1.1.03.01')->id, '100.00')
            ->credit(account('4.1.01')->id, '100.00')
    );

    actingAsUserOf($companyA, role: PermissionCatalog::ACCOUNTANT);

    expect(fn () => Livewire::test(JournalIndex::class)->call('confirmVoid', $entryDeB->id))
        ->toThrow(ModelNotFoundException::class);

    expect(acrossCompanies(fn () => JournalEntry::acrossCompanies()->find($entryDeB->id)->status->value))
        ->toBe('posted');
});
