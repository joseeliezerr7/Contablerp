<?php

declare(strict_types=1);

use App\Domains\Accounting\Enums\AccountMappingKey;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\AccountMapping;
use App\Domains\Accounting\Services\AccountMappingService;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Taxation\Models\Tax;
use App\Livewire\Accounting\AccountMappingIndex;
use App\Livewire\Taxation\TaxIndex;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/**
 * Las dos pantallas de configuración contable.
 *
 * Son las que un contador toca una vez al instalar y casi nunca más, y
 * justamente por eso tienen que estar bien: un error aquí no se nota el día que
 * se comete sino tres meses después, cuando el balance no cuadra.
 */
beforeEach(function () {
    [$this->company, $this->contador] = accountingCompanyWithAccountant();
    $this->mappings = app(AccountMappingService::class);
});

/*
|--------------------------------------------------------------------------
| Cuentas por módulo
|--------------------------------------------------------------------------
*/

it('muestra todas las claves del enum, no solo las configuradas', function () {
    $componente = Livewire::test(AccountMappingIndex::class);

    foreach (AccountMappingKey::cases() as $key) {
        $componente->assertSee($key->label());
    }
});

it('reasigna una clave a otra cuenta', function () {
    // La cuenta de ventas del catálogo hondureño y otra cualquiera de detalle.
    $otra = Account::query()->where('is_postable', true)
        ->whereKeyNot($this->mappings->resolveId(AccountMappingKey::SalesRevenue))
        ->firstOrFail();

    Livewire::test(AccountMappingIndex::class)
        ->set('selected.'.AccountMappingKey::SalesRevenue->name, $otra->id)
        ->call('assign', AccountMappingKey::SalesRevenue->name)
        ->assertHasNoErrors();

    // El servicio cachea por petición, así que se pregunta con uno nuevo.
    expect(app(AccountMappingService::class)->resolveId(AccountMappingKey::SalesRevenue))
        ->toBe($otra->id);
});

it('rechaza una cuenta de resumen, que no admite movimientos', function () {
    $resumen = Account::query()->where('is_postable', false)->firstOrFail();

    Livewire::test(AccountMappingIndex::class)
        ->set('selected.'.AccountMappingKey::SalesRevenue->name, $resumen->id)
        ->call('assign', AccountMappingKey::SalesRevenue->name)
        ->assertHasErrors('selected.'.AccountMappingKey::SalesRevenue->name);

    // Y la asignación anterior sigue en pie.
    expect(app(AccountMappingService::class)->resolveId(AccountMappingKey::SalesRevenue))
        ->not->toBe($resumen->id);
});

it('avisa de las claves que ninguna cuenta cubre', function () {
    AccountMapping::query()->whereIn('key', [
        AccountMappingKey::SalesReturns->value,
        AccountMappingKey::SalesDiscount->value,
    ])->delete();

    Livewire::test(AccountMappingIndex::class)
        ->assertSee('Devoluciones sobre ventas')
        ->assertSee('Faltan 2 cuentas por asignar');
});

it('usa el singular cuando falta una sola', function () {
    AccountMapping::query()->where('key', AccountMappingKey::SalesReturns->value)->delete();

    Livewire::test(AccountMappingIndex::class)
        ->assertSee('Falta una cuenta por asignar');
});

it('no reasigna nada desde otra empresa', function () {
    $ajena = accountingCompany();

    $cuentaAjena = app(CompanyContext::class)->runFor(
        $ajena,
        fn () => Account::query()->where('is_postable', true)->firstOrFail(),
    );

    // De vuelta como contador de la primera.
    $this->actingAs($this->contador);
    app(CompanyContext::class)->set($this->company);

    Livewire::test(AccountMappingIndex::class)
        ->set('selected.'.AccountMappingKey::SalesRevenue->name, $cuentaAjena->id)
        ->call('assign', AccountMappingKey::SalesRevenue->name);
})->throws(ModelNotFoundException::class);

it('deja mirar al auditor pero no reasignar', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::AUDITOR);

    $this->get(route('accounting.mappings.index'))->assertOk();

    Livewire::test(AccountMappingIndex::class)
        ->call('assign', AccountMappingKey::SalesRevenue->name)
        ->assertForbidden();
});

it('le niega la pantalla a quien no lleva la contabilidad', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::CASHIER);

    $this->get(route('accounting.mappings.index'))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Impuestos
|--------------------------------------------------------------------------
*/

it('crea un impuesto con su tasa y sus dos cuentas', function () {
    $porPagar = Account::query()->where('is_postable', true)->firstOrFail();
    $acreditable = Account::query()->where('is_postable', true)->whereKeyNot($porPagar->id)->firstOrFail();

    Livewire::test(TaxIndex::class)
        ->call('create')
        ->set('code', 'ISVTUR')
        ->set('name', 'ISV turismo')
        ->set('rate', '18')
        ->set('payable_account_id', $porPagar->id)
        ->set('creditable_account_id', $acreditable->id)
        ->call('save')
        ->assertHasNoErrors();

    $tax = Tax::query()->where('code', 'ISVTUR')->firstOrFail();

    expect($tax->company_id)->toBe($this->company->id)
        ->and((string) $tax->rate)->toBe('18.000000')
        ->and($tax->payable_account_id)->toBe($porPagar->id)
        ->and($tax->is_active)->toBeTrue();
});

it('acepta la tasa cero, que es una exoneración y no la ausencia de impuesto', function () {
    Livewire::test(TaxIndex::class)
        ->call('create')
        ->set('code', 'EXO')
        ->set('name', 'Exonerado')
        ->set('rate', '0')
        ->call('save')
        ->assertHasNoErrors();

    expect(Tax::query()->where('code', 'EXO')->firstOrFail()->isZeroRated())->toBeTrue();
});

it('no admite dos impuestos con el mismo código en la empresa', function () {
    Livewire::test(TaxIndex::class)
        ->call('create')
        ->set('code', tax('ISV15')->code)
        ->set('name', 'Repetido')
        ->set('rate', '15')
        ->call('save')
        ->assertHasErrors('code');
});

it('sí admite el mismo código en otra empresa', function () {
    Livewire::test(TaxIndex::class)
        ->call('create')
        ->set('code', 'ISVTUR')
        ->set('name', 'ISV turismo')
        ->set('rate', '18')
        ->call('save')
        ->assertHasNoErrors();

    // La misma empresa lo rechazaría; otra no tiene por qué enterarse.
    $ajena = accountingCompany();
    actingAsUserOf($ajena, role: PermissionCatalog::ACCOUNTANT);

    Livewire::test(TaxIndex::class)
        ->call('create')
        ->set('code', 'ISVTUR')
        ->set('name', 'ISV turismo')
        ->set('rate', '18')
        ->call('save')
        ->assertHasNoErrors();

    // Dos filas, una en cada empresa. Se cuenta sin el scope porque el scope
    // es justamente lo que esconde la de la otra.
    expect(Tax::query()->withoutGlobalScopes()->where('code', 'ISVTUR')->count())->toBe(2);
});

it('deja un solo impuesto predeterminado', function () {
    $anterior = Tax::query()->where('is_default', true)->firstOrFail();

    Livewire::test(TaxIndex::class)
        ->call('create')
        ->set('code', 'ISVTUR')
        ->set('name', 'ISV turismo')
        ->set('rate', '18')
        ->set('is_default', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Tax::query()->where('is_default', true)->count())->toBe(1)
        ->and($anterior->refresh()->is_default)->toBeFalse();
});

it('desactiva en vez de borrar, y le quita lo de predeterminado', function () {
    $isv = tax('ISV15');
    $isv->forceFill(['is_default' => true])->save();

    Livewire::test(TaxIndex::class)->call('toggleActive', $isv->id);

    $isv->refresh();

    expect($isv->is_active)->toBeFalse()
        ->and($isv->is_default)->toBeFalse()
        // Sigue existiendo: las facturas que lo usaron lo referencian.
        ->and(Tax::query()->whereKey($isv->id)->exists())->toBeTrue();
});

it('no toca un impuesto de otra empresa', function () {
    $ajena = accountingCompany();

    $ajeno = app(CompanyContext::class)->runFor(
        $ajena,
        fn () => Tax::query()->where('code', 'ISV15')->firstOrFail(),
    );

    $this->actingAs($this->contador);
    app(CompanyContext::class)->set($this->company);

    Livewire::test(TaxIndex::class)->call('edit', $ajeno->id);
})->throws(ModelNotFoundException::class);

it('deja ver los impuestos al auditor pero no configurarlos', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::AUDITOR);

    $this->get(route('taxes.index'))->assertOk();

    Livewire::test(TaxIndex::class)->call('create')->assertForbidden();
});

it('le niega los impuestos al vendedor', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::SALESPERSON);

    $this->get(route('taxes.index'))->assertForbidden();
});
