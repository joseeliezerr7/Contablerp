<?php

declare(strict_types=1);

use App\Domains\Assets\Models\FixedAsset;
use App\Domains\Assets\Models\FixedAssetCategory;
use App\Domains\Assets\Services\DepreciationService;
use App\Domains\Assets\Services\FixedAssetService;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;

/**
 * Qué alcanza cada rol en activos fijos y retenciones.
 *
 * Es la prueba que las fases 5 y 6 enseñaron a escribir: en las dos, un módulo
 * quedó inalcanzable para el contador porque los permisos se repartieron
 * pensando en una empresa grande, y ninguna prueba lo detectó porque ninguna
 * probaba las pantallas.
 */
beforeEach(function () {
    $this->company = accountingCompany();
});

/**
 * Deja un activo dado de alta, como contador.
 */
function seedAssetFixture(object $ctx): int
{
    actingAsUserOf($ctx->company, role: PermissionCatalog::ACCOUNTANT);

    $category = new FixedAssetCategory;
    $category->forceFill([
        'company_id' => app(CompanyContext::class)->idOrFail(),
        'code' => 'COMP',
        'name' => 'Equipo de cómputo',
        'useful_life_months' => 36,
        'asset_account_id' => account('1.2.01.04')->id,
        'depreciation_account_id' => account('6.1.06')->id,
        'accumulated_account_id' => account('1.2.02.03')->id,
        'is_active' => true,
    ])->save();

    $asset = app(FixedAssetService::class)->create([
        'branch_id' => mainBranch()->id,
        'fixed_asset_category_id' => $category->id,
        'code' => 'AF-001',
        'name' => 'Laptop',
        'acquired_on' => '2026-01-10',
        'cost' => '36000.00',
        'salvage_value' => '0',
        'useful_life_months' => 36,
    ]);

    return $asset->id;
}

it('deja al contador entrar a las tres pantallas y operarlas', function () {
    seedAssetFixture($this);

    $this->get(route('assets.index'))->assertOk();
    $this->get(route('assets.depreciation.index'))->assertOk();
    $this->get(route('taxes.withholdings.index'))->assertOk();

    $user = auth()->user();

    expect($user->can('assets.manage'))->toBeTrue()
        ->and($user->can('assets.dispose'))->toBeTrue()
        // De esto depende el botón de generar la depreciación.
        ->and($user->can('assets.depreciation.run'))->toBeTrue()
        ->and($user->can('taxes.withholdings.manage'))->toBeTrue();
});

it('deja al gerente mirar sin poder correr la depreciación', function () {
    seedAssetFixture($this);

    actingAsUserOf($this->company, role: PermissionCatalog::MANAGER);

    $this->get(route('assets.index'))->assertOk();
    $this->get(route('assets.depreciation.index'))->assertOk();

    expect(auth()->user()->can('assets.depreciation.run'))->toBeFalse()
        ->and(auth()->user()->can('assets.manage'))->toBeFalse();
});

it('deja al auditor mirar sin tocar nada', function () {
    seedAssetFixture($this);

    actingAsUserOf($this->company, role: PermissionCatalog::AUDITOR);

    $this->get(route('assets.index'))->assertOk();
    $this->get(route('taxes.withholdings.index'))->assertOk();

    expect(auth()->user()->can('assets.manage'))->toBeFalse()
        ->and(auth()->user()->can('assets.dispose'))->toBeFalse()
        ->and(auth()->user()->can('assets.depreciation.void'))->toBeFalse();
});

it('deja fuera al vendedor y al bodeguero', function () {
    seedAssetFixture($this);

    foreach ([PermissionCatalog::SALESPERSON, PermissionCatalog::WAREHOUSE] as $role) {
        actingAsUserOf($this->company, role: $role);

        $this->get(route('assets.index'))->assertForbidden();
        $this->get(route('assets.depreciation.index'))->assertForbidden();
        $this->get(route('taxes.withholdings.index'))->assertForbidden();
    }
});

it('no deja borrar un activo que ya depreció', function () {
    $assetId = seedAssetFixture($this);

    app(DepreciationService::class)->run('2026-02-01');

    $asset = FixedAsset::query()->findOrFail($assetId);

    // Dejó rastro en el libro: se da de baja, no se borra.
    expect(auth()->user()->can('delete', $asset))->toBeFalse()
        ->and(auth()->user()->can('dispose', $asset))->toBeTrue();
});

it('no deja editar ni dar de baja dos veces un activo ya dado de baja', function () {
    $assetId = seedAssetFixture($this);

    $asset = FixedAsset::query()->findOrFail($assetId);

    app(FixedAssetService::class)->dispose(
        $asset,
        '2026-03-01',
        Money::of('10000.00'),
        'Vendida a un empleado',
        account('1.1.02.01')->id,
    );

    $asset->refresh();

    expect(auth()->user()->can('update', $asset))->toBeFalse()
        ->and(auth()->user()->can('dispose', $asset))->toBeFalse();
});
