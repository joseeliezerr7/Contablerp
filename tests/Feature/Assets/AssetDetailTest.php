<?php

declare(strict_types=1);

use App\Domains\Assets\Models\DepreciationRun;
use App\Domains\Assets\Models\FixedAsset;
use App\Domains\Assets\Models\FixedAssetCategory;
use App\Domains\Assets\Services\DepreciationService;
use App\Domains\Assets\Services\FixedAssetService;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Livewire\Assets\DepreciationShow;
use App\Livewire\Assets\FixedAssetShow;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Livewire\Livewire;

/**
 * Ver un activo y una corrida de depreciación.
 *
 * `depreciation_run_lines` guarda desde la Fase 7 una fila por activo y por mes
 * —cuota, acumulada y valor en libros de ese momento— y **ninguna pantalla la
 * leía**. El listado de activos mostraba solo el acumulado de hoy, y el de
 * corridas solo el total del mes: a «¿por qué agosto dio más que julio?» no
 * había cómo responder sin entrar a la base.
 */
beforeEach(function () {
    $this->company = accountingCompany();
    $this->accountant = actingAsUserOf($this->company, role: PermissionCatalog::ACCOUNTANT);
});

/**
 * Categoría de cómputo: 36 meses contra las cuentas del plan hondureño.
 */
function detailCategory(string $code = 'COMP'): FixedAssetCategory
{
    $category = new FixedAssetCategory;

    $category->forceFill([
        'company_id' => app(CompanyContext::class)->idOrFail(),
        'code' => $code,
        'name' => 'Equipo de cómputo',
        'useful_life_months' => 36,
        'asset_account_id' => account('1.2.01.04')->id,
        'depreciation_account_id' => account('6.1.06')->id,
        'accumulated_account_id' => account('1.2.02.03')->id,
        'is_active' => true,
    ])->save();

    return $category;
}

/**
 * Activo de 36 000 a 36 meses: cuota redonda de 1 000.
 */
function detailAsset(string $code = 'AF-001', string $cost = '36000.00'): FixedAsset
{
    return app(FixedAssetService::class)->create([
        'branch_id' => mainBranch()->id,
        'fixed_asset_category_id' => detailCategory($code === 'AF-001' ? 'COMP' : 'COMP2')->id,
        'code' => $code,
        'name' => 'Laptop',
        'acquired_on' => now()->startOfYear()->toDateString(),
        'cost' => $cost,
        'salvage_value' => '0',
        'useful_life_months' => 36,
    ]);
}

/**
 * Corre la depreciación del mes siguiente al de la compra, que es cuando el
 * motor la arranca.
 */
function detailRun(FixedAsset $asset): DepreciationRun
{
    return app(DepreciationService::class)->run(
        $asset->acquired_on->copy()->addMonth()->toDateString()
    );
}

/*
|--------------------------------------------------------------------------
| El activo
|--------------------------------------------------------------------------
*/

it('muestra el costo, lo depreciado y lo que queda en libros', function () {
    $asset = detailAsset();
    detailRun($asset);

    Livewire::test(FixedAssetShow::class, ['asset' => $asset->id])
        ->assertSee('AF-001')
        ->assertSee('36,000.00')
        // Una cuota de 1 000 aplicada: quedan 35 000 en libros.
        ->assertSee('1,000.00')
        ->assertSee('35,000.00');
});

it('lista la historia de depreciación mes a mes', function () {
    $asset = detailAsset();

    // Tres meses seguidos.
    for ($i = 1; $i <= 3; $i++) {
        app(DepreciationService::class)->run(
            $asset->acquired_on->copy()->addMonths($i)->toDateString()
        );
    }

    $lines = Livewire::test(FixedAssetShow::class, ['asset' => $asset->id])->viewData('lines');

    expect($lines)->toHaveCount(3);

    // De la más reciente a la más vieja, y el acumulado creciendo de mil en mil.
    expect($lines->first()->accumulatedAfter()->format())->toBe('3,000.00')
        ->and($lines->last()->accumulatedAfter()->format())->toBe('1,000.00')
        ->and($lines->last()->bookValueAfter()->format())->toBe('35,000.00');
});

it('dice que todavía no deprecia cuando no hay ninguna cuota', function () {
    $asset = detailAsset();

    Livewire::test(FixedAssetShow::class, ['asset' => $asset->id])
        ->assertSee('Todavía no se le ha aplicado ninguna cuota')
        // Y explica cuándo va a empezar, que es la pregunta que sigue.
        ->assertSee('La depreciación arranca el mes siguiente al de la compra');
});

/*
|--------------------------------------------------------------------------
| La corrida
|--------------------------------------------------------------------------
*/

it('desglosa la corrida activo por activo', function () {
    $asset = detailAsset();
    $run = detailRun($asset);

    Livewire::test(DepreciationShow::class, ['run' => $run->id])
        ->assertSee($run->number)
        ->assertSee('Activo por activo')
        ->assertSee('AF-001')
        ->assertSee('1,000.00');
});

it('agrupa la corrida por categoría antes del detalle', function () {
    $asset = detailAsset();
    $run = detailRun($asset);

    $porCategoria = Livewire::test(DepreciationShow::class, ['run' => $run->id])
        ->viewData('byCategory');

    expect($porCategoria)->toHaveCount(1)
        ->and($porCategoria['Equipo de cómputo']['count'])->toBe(1)
        ->and($porCategoria['Equipo de cómputo']['total']->format())->toBe('1,000.00');
});

it('enlaza la corrida con su partida contable', function () {
    $asset = detailAsset();
    $run = detailRun($asset);

    Livewire::test(DepreciationShow::class, ['run' => $run->id])
        ->assertSee($run->journalEntry()->number);
});

it('conserva la corrida anulada para que la historia no quede con huecos', function () {
    $asset = detailAsset();
    $run = detailRun($asset);

    app(DepreciationService::class)->void($run, 'Se corrió con la tasa equivocada');

    Livewire::test(DepreciationShow::class, ['run' => $run->id])
        ->assertSee('Corrida anulada');

    // Y desde el activo se sigue viendo, marcada.
    Livewire::test(FixedAssetShow::class, ['asset' => $asset->id])
        ->assertSee($run->number)
        ->assertSee('anulada');
});

/*
|--------------------------------------------------------------------------
| Aislamiento y permisos
|--------------------------------------------------------------------------
*/

it('no abre el activo de otra empresa', function () {
    $otra = accountingCompany();

    $ajeno = app(CompanyContext::class)->runFor($otra, function (): FixedAsset {
        actingAsUserOf(app(CompanyContext::class)->companyOrFail(), role: PermissionCatalog::ACCOUNTANT);

        return detailAsset();
    });

    $this->actingAs($this->accountant);
    app(CompanyContext::class)->set($this->company);

    Livewire::test(FixedAssetShow::class, ['asset' => $ajeno->id]);
})->throws(ModelNotFoundException::class);

it('le niega el activo a quien no ve activos', function () {
    $asset = detailAsset();

    actingAsUserOf($this->company, role: PermissionCatalog::SALESPERSON);

    $this->get(route('assets.show', $asset->id))->assertForbidden();
});

it('ofrece «Ver» en los dos listados', function () {
    $asset = detailAsset();
    $run = detailRun($asset);

    $this->get(route('assets.index'))
        ->assertOk()
        ->assertSee(route('assets.show', $asset->id));

    $this->get(route('assets.depreciation.index'))
        ->assertOk()
        ->assertSee(route('assets.depreciation.show', $run->id));
});

it('la ruta del activo no se traga /activos/categorias', function () {
    $resolve = fn (string $uri) => app('router')->getRoutes()
        ->match(Request::create($uri))
        ->getName();

    expect($resolve('/activos/categorias'))->toBe('assets.categories.index')
        ->and($resolve('/activos/depreciacion'))->toBe('assets.depreciation.index')
        ->and($resolve('/activos/7'))->toBe('assets.show')
        ->and($resolve('/activos/depreciacion/7'))->toBe('assets.depreciation.show');
});
