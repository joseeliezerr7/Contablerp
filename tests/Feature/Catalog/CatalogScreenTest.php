<?php

declare(strict_types=1);

use App\Domains\Assets\Models\FixedAssetCategory;
use App\Domains\Assets\Services\FixedAssetService;
use App\Domains\Catalog\Models\PriceList;
use App\Domains\Catalog\Models\ProductCategory;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Livewire\Assets\AssetCategoryIndex;
use App\Livewire\Catalog\CatalogIndex;
use App\Livewire\Sales\ProductIndex;
use App\Support\Tenancy\CompanyContext;
use Livewire\Livewire;

/**
 * Los catálogos maestros.
 *
 * No faltaba una pantalla: faltaba que el sistema se pudiera configurar. Las
 * categorías de producto **nunca se sembraron y no tenían pantalla**, así que el
 * selector del formulario de producto no podía llenarse jamás; y las categorías
 * de activo, que el alta de activos exige, tampoco existían fuera de la empresa
 * de demostración.
 */
beforeEach(function () {
    [$this->company, $this->accountant] = accountingCompanyWithAccountant();
});

/*
|--------------------------------------------------------------------------
| Lo que estaba roto
|--------------------------------------------------------------------------
*/

it('deja crear la primera categoría de producto y la ofrece en el formulario', function () {
    // Una empresa recién creada no tiene ninguna: `CatalogProvisioner` siembra
    // unidades, listas de precios e impuestos, pero categorías no.
    expect(ProductCategory::query()->count())->toBe(0);

    Livewire::test(CatalogIndex::class)
        ->set('tab', 'categorias')
        ->call('create')
        ->set('code', 'FERR')
        ->set('name', 'Ferretería general')
        ->call('save')
        ->assertHasNoErrors();

    expect(ProductCategory::query()->where('code', 'FERR')->exists())->toBeTrue();

    // Y ya se puede elegir donde antes el selector salía vacío para siempre.
    $ofrecidas = Livewire::test(ProductIndex::class)->viewData('categories');

    expect($ofrecidas->pluck('name')->all())->toContain('Ferretería general');
});

it('deja crear la primera categoría de activo, sin la cual no hay activos fijos', function () {
    expect(FixedAssetCategory::query()->count())->toBe(0);

    Livewire::test(AssetCategoryIndex::class)
        ->call('create')
        ->set('code', 'MAQ')
        ->set('name', 'Maquinaria y equipo')
        ->set('useful_life_months', '120')
        ->set('asset_account_id', account('1.2.01.01')->id)
        ->set('depreciation_account_id', account('6.1.06')->id)
        ->set('accumulated_account_id', account('1.2.02.01')->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(FixedAssetCategory::query()->where('code', 'MAQ')->first()?->useful_life_months)->toBe(120);
});

/*
|--------------------------------------------------------------------------
| Las guardas
|--------------------------------------------------------------------------
*/

it('no admite dos códigos iguales en la misma empresa', function () {
    Livewire::test(CatalogIndex::class)
        ->call('create')
        ->set('code', 'DOC')
        ->set('name', 'Docena')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(CatalogIndex::class)
        ->call('create')
        ->set('code', 'DOC')
        ->set('name', 'Docena repetida')
        ->call('save')
        ->assertHasErrors('code');
});

it('sí admite el mismo código en dos empresas distintas', function () {
    // El índice único es (company_id, code): dos clientes pueden tener cada uno
    // su unidad «DOC» sin estorbarse.
    Livewire::test(CatalogIndex::class)
        ->call('create')->set('code', 'DOC')->set('name', 'Docena')->call('save');

    $otra = accountingCompany();

    app(CompanyContext::class)->runFor($otra, function (): void {
        actingAsUserOf(app(CompanyContext::class)->companyOrFail(), role: PermissionCatalog::ACCOUNTANT);

        Livewire::test(CatalogIndex::class)
            ->call('create')->set('code', 'DOC')->set('name', 'Docena')->call('save')
            ->assertHasNoErrors();
    });

    expect(Unit::withoutGlobalScopes()->where('code', 'DOC')->count())->toBe(2);
});

it('no lista los catálogos de otra empresa', function () {
    $otra = accountingCompany();

    app(CompanyContext::class)->runFor($otra, function (): void {
        actingAsUserOf(app(CompanyContext::class)->companyOrFail(), role: PermissionCatalog::ACCOUNTANT);

        Livewire::test(CatalogIndex::class)
            ->set('tab', 'categorias')
            ->call('create')->set('code', 'AJE')->set('name', 'Categoría ajena')->call('save');
    });

    $this->actingAs($this->accountant);
    app(CompanyContext::class)->set($this->company);

    Livewire::test(CatalogIndex::class)
        ->set('tab', 'categorias')
        ->assertDontSee('Categoría ajena');
});

it('desactiva en vez de borrar', function () {
    $unidad = Unit::query()->where('code', 'CJA')->firstOrFail();

    Livewire::test(CatalogIndex::class)->call('toggleActive', $unidad->id);

    // Sigue existiendo: los productos vendidos apuntan a ella.
    expect($unidad->refresh()->is_active)->toBeFalse()
        ->and(Unit::query()->whereKey($unidad->id)->exists())->toBeTrue();
});

it('no deja apagar la lista de precios predeterminada', function () {
    $predeterminada = PriceList::query()->where('is_default', true)->firstOrFail();

    Livewire::test(CatalogIndex::class)
        ->set('tab', 'precios')
        ->call('toggleActive', $predeterminada->id);

    // Sin una lista predeterminada, el formulario de venta no tendría con qué
    // arrancar.
    expect($predeterminada->refresh()->is_active)->toBeTrue();
});

it('deja una sola lista predeterminada a la vez', function () {
    $anterior = PriceList::query()->where('is_default', true)->firstOrFail();
    $otra = PriceList::query()->where('is_default', false)->firstOrFail();

    Livewire::test(CatalogIndex::class)
        ->set('tab', 'precios')
        ->call('edit', $otra->id)
        ->set('is_default', true)
        ->call('save')
        ->assertHasNoErrors();

    // Si dos quedaran marcadas, el formulario de venta elegiría una al azar y el
    // mismo producto se cobraría a precios distintos.
    expect($otra->refresh()->is_default)->toBeTrue()
        ->and($anterior->refresh()->is_default)->toBeFalse()
        ->and(PriceList::query()->where('is_default', true)->count())->toBe(1);
});

it('no borra una categoría de activo que ya tiene activos', function () {
    $categoria = FixedAssetCategory::query()->create([
        'code' => 'MOB',
        'name' => 'Mobiliario',
        'useful_life_months' => 60,
        'asset_account_id' => account('1.2.01.03')->id,
        'depreciation_account_id' => account('6.1.06')->id,
        'accumulated_account_id' => account('1.2.02.02')->id,
        'is_active' => true,
    ]);

    app(FixedAssetService::class)->create([
        'branch_id' => mainBranch()->id,
        'fixed_asset_category_id' => $categoria->id,
        'code' => 'AF-001',
        'name' => 'Escritorio',
        'acquired_on' => now()->toDateString(),
        'cost' => '12000.00',
        'salvage_value' => '0',
        'useful_life_months' => 60,
    ]);

    Livewire::test(AssetCategoryIndex::class)
        ->call('delete', $categoria->id)
        ->assertForbidden();

    expect(FixedAssetCategory::query()->whereKey($categoria->id)->exists())->toBeTrue();
});

it('rechaza una cuenta contable de otra empresa', function () {
    $otra = accountingCompany();

    $ajena = app(CompanyContext::class)->runFor(
        $otra,
        fn () => account('1.2.01.03')->id,
    );

    $this->actingAs($this->accountant);
    app(CompanyContext::class)->set($this->company);

    // `exists:` habría dejado pasar esto y el asiento de depreciación saldría
    // contra el plan de cuentas de otro cliente.
    Livewire::test(AssetCategoryIndex::class)
        ->call('create')
        ->set('code', 'MAQ')
        ->set('name', 'Maquinaria')
        ->set('useful_life_months', '120')
        ->set('asset_account_id', $ajena)
        ->set('depreciation_account_id', account('6.1.06')->id)
        ->set('accumulated_account_id', account('1.2.02.01')->id)
        ->call('save')
        ->assertHasErrors('asset_account_id');
});

it('exige al menos doce meses de vida útil', function () {
    // Lo que dura menos de un año es gasto del período, no un activo que se
    // deprecie.
    Livewire::test(AssetCategoryIndex::class)
        ->call('create')
        ->set('code', 'TMP')
        ->set('name', 'Temporal')
        ->set('useful_life_months', '6')
        ->set('asset_account_id', account('1.2.01.03')->id)
        ->set('depreciation_account_id', account('6.1.06')->id)
        ->set('accumulated_account_id', account('1.2.02.02')->id)
        ->call('save')
        ->assertHasErrors('useful_life_months');
});

/*
|--------------------------------------------------------------------------
| Permisos
|--------------------------------------------------------------------------
*/

it('le niega la pantalla al vendedor', function () {
    // Un vendedor ve «CJA» y «Mayorista» en los selectores, que no comprueban
    // este permiso; la pantalla que los mantiene no es suya.
    actingAsUserOf($this->company, role: PermissionCatalog::SALESPERSON);

    $this->get(route('catalog.index'))->assertForbidden();
});

it('deja mirar al auditor pero no tocar', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::AUDITOR);

    $this->get(route('catalog.index'))->assertOk();

    Livewire::test(CatalogIndex::class)->call('create')->assertForbidden();
});

it('la pestaña que llega por la URL no puede ser cualquier cosa', function () {
    Livewire::test(CatalogIndex::class, ['tab' => 'lo-que-sea'])
        ->assertSet('tab', 'unidades')
        ->assertOk();
});
