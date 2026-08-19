<?php

declare(strict_types=1);

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Assets\Enums\FixedAssetStatus;
use App\Domains\Assets\Exceptions\AssetException;
use App\Domains\Assets\Models\DepreciationRun;
use App\Domains\Assets\Models\FixedAsset;
use App\Domains\Assets\Models\FixedAssetCategory;
use App\Domains\Assets\Services\DepreciationService;
use App\Domains\Assets\Services\FixedAssetService;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();

    $this->assets = app(FixedAssetService::class);
    $this->depreciation = app(DepreciationService::class);
    $this->branch = mainBranch();

    $this->category = assetCategory();
});

/**
 * Categoría de equipo de cómputo con sus tres cuentas.
 */
function assetCategory(string $code = 'COMP'): FixedAssetCategory
{
    if ($existing = FixedAssetCategory::query()->where('code', $code)->first()) {
        return $existing;
    }

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
 * Da de alta un activo.
 */
function registerAsset(
    object $ctx,
    string $cost = '36000.00',
    string $acquired = '2026-01-15',
    string $salvage = '0',
    int $life = 36,
    string $code = 'AF-001',
): FixedAsset {
    return $ctx->assets->create([
        'branch_id' => $ctx->branch->id,
        'fixed_asset_category_id' => $ctx->category->id,
        'code' => $code,
        'name' => 'Laptop de gerencia',
        'acquired_on' => $acquired,
        'cost' => $cost,
        'salvage_value' => $salvage,
        'useful_life_months' => $life,
    ]);
}

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

it('da de alta el activo sin generar partida', function () {
    $activo = registerAsset($this);

    // La compra ya se contabilizó: darlo de alta no vuelve a registrarlo.
    expect($activo->status)->toBe(FixedAssetStatus::Active)
        ->and($activo->bookValue()->toString())->toBe('36000.0000')
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('calcula la cuota mensual en línea recta', function () {
    $activo = registerAsset($this, cost: '36000.00', life: 36);

    expect($activo->monthlyQuota()->toString())->toBe('1000.0000');
});

it('descuenta el valor residual de la base depreciable', function () {
    $activo = registerAsset($this, cost: '40000.00', salvage: '4000.00', life: 36);

    expect($activo->monthlyQuota()->toString())->toBe('1000.0000');
});

it('rechaza un residual mayor que el costo', function () {
    expect(fn () => registerAsset($this, cost: '1000.00', salvage: '2000.00'))
        ->toThrow(AssetException::class, 'no puede ser mayor que el costo');
});

/*
|--------------------------------------------------------------------------
| La corrida mensual
|--------------------------------------------------------------------------
*/

it('no deprecia el mes de la compra', function () {
    registerAsset($this, acquired: '2026-01-15');

    expect(fn () => $this->depreciation->run('2026-01-01'))
        ->toThrow(AssetException::class, 'No hay activos que depreciar');
});

it('deprecia desde el mes siguiente al de la compra', function () {
    registerAsset($this, acquired: '2026-01-15');

    $corrida = $this->depreciation->run('2026-02-01');

    expect($corrida->totalAmount()->toString())->toBe('1000.0000')
        ->and($corrida->asset_count)->toBe(1)
        ->and($corrida->number)->toBe('DEP-000001');
});

it('genera una sola partida por corrida, agrupada por cuenta', function () {
    registerAsset($this, code: 'AF-001');
    registerAsset($this, code: 'AF-002', cost: '18000.00');

    $corrida = $this->depreciation->run('2026-02-01');
    $lines = $corrida->journalEntry()->lines;

    expect($corrida->asset_count)->toBe(2)
        ->and($corrida->totalAmount()->toString())->toBe('1500.0000')
        // Dos activos, misma categoría: dos líneas, no cuatro.
        ->and($lines)->toHaveCount(2)
        ->and($lines->firstWhere('account_id', account('6.1.06')->id)->debitAmount()->toString())->toBe('1500.0000')
        ->and($lines->firstWhere('account_id', account('1.2.02.03')->id)->creditAmount()->toString())->toBe('1500.0000');
});

it('acumula la depreciación en el activo', function () {
    $activo = registerAsset($this);

    $this->depreciation->run('2026-02-01');
    $this->depreciation->run('2026-03-01');

    $activo->refresh();

    expect($activo->accumulated()->toString())->toBe('2000.0000')
        ->and($activo->bookValue()->toString())->toBe('34000.0000')
        ->and($activo->depreciated_through->format('Y-m'))->toBe('2026-03');
});

it('rechaza depreciar dos veces el mismo mes', function () {
    registerAsset($this);
    $this->depreciation->run('2026-02-01');

    expect(fn () => $this->depreciation->run('2026-02-01'))
        ->toThrow(AssetException::class, 'ya se ejecutó');
});

/*
|--------------------------------------------------------------------------
| El centavo del último mes
|--------------------------------------------------------------------------
*/

it('deja el activo exactamente en su residual al terminar la vida útil', function () {
    // Tres años de depreciación cruzan de ejercicio: hay que abrirlos.
    openFiscalYears(2027, 2028, 2029);

    // 10 000 entre 36 no reparte: la cuota es 277.7778.
    $activo = registerAsset($this, cost: '10000.00', acquired: '2026-01-31', life: 36);

    for ($i = 1; $i <= 36; $i++) {
        $this->depreciation->run(CarbonImmutable::parse('2026-02-01')->addMonths($i - 1));
    }

    $activo->refresh();

    expect($activo->accumulated()->toString())->toBe('10000.0000')
        ->and($activo->bookValue()->toString())->toBe('0.0000')
        ->and($activo->status)->toBe(FixedAssetStatus::FullyDepreciated);
});

it('no deprecia por debajo del residual', function () {
    openFiscalYears(2027, 2028, 2029);

    $activo = registerAsset($this, cost: '10000.00', salvage: '1000.00', acquired: '2026-01-31', life: 36);

    for ($i = 1; $i <= 40; $i++) {
        try {
            $this->depreciation->run(CarbonImmutable::parse('2026-02-01')->addMonths($i - 1));
        } catch (AssetException) {
            break;   // Ya no queda nada que depreciar.
        }
    }

    $activo->refresh();

    expect($activo->bookValue()->toString())->toBe('1000.0000')
        ->and($activo->status)->toBe(FixedAssetStatus::FullyDepreciated);
});

it('deja de incluir en la corrida al activo totalmente depreciado', function () {
    registerAsset($this, cost: '3000.00', acquired: '2026-01-31', life: 3, code: 'AF-CORTO');
    registerAsset($this, cost: '36000.00', acquired: '2026-01-31', life: 36, code: 'AF-LARGO');

    for ($i = 1; $i <= 4; $i++) {
        $corrida = $this->depreciation->run(CarbonImmutable::parse('2026-02-01')->addMonths($i - 1));
    }

    // En el cuarto mes solo queda el activo de vida larga.
    expect($corrida->asset_count)->toBe(1)
        ->and($corrida->totalAmount()->toString())->toBe('1000.0000');
});

/*
|--------------------------------------------------------------------------
| Anulación
|--------------------------------------------------------------------------
*/

it('devuelve el acumulado al anular la corrida', function () {
    $activo = registerAsset($this);

    $this->depreciation->run('2026-02-01');
    $corrida = $this->depreciation->run('2026-03-01');

    $this->depreciation->void($corrida, 'Se capturó mal un activo');

    $activo->refresh();

    expect($activo->accumulated()->toString())->toBe('1000.0000')
        ->and($activo->depreciated_through->format('Y-m'))->toBe('2026-02')
        ->and($corrida->refresh()->isVoided())->toBeTrue();
});

it('permite volver a correr el mes después de anularlo', function () {
    registerAsset($this);

    $corrida = $this->depreciation->run('2026-02-01');
    $this->depreciation->void($corrida, 'Corrección');

    $nueva = $this->depreciation->run('2026-02-01');

    expect($nueva->totalAmount()->toString())->toBe('1000.0000')
        ->and($nueva->id)->not->toBe($corrida->id);
});

it('impide anular un mes intermedio', function () {
    registerAsset($this);

    $febrero = $this->depreciation->run('2026-02-01');
    $this->depreciation->run('2026-03-01');

    expect(fn () => $this->depreciation->void($febrero, 'Intento'))
        ->toThrow(AssetException::class, 'hay corridas posteriores');
});

it('reactiva el activo totalmente depreciado al anular su última corrida', function () {
    $activo = registerAsset($this, cost: '3000.00', acquired: '2026-01-31', life: 3);

    $this->depreciation->run('2026-02-01');
    $this->depreciation->run('2026-03-01');
    $ultima = $this->depreciation->run('2026-04-01');

    expect($activo->refresh()->status)->toBe(FixedAssetStatus::FullyDepreciated);

    $this->depreciation->void($ultima, 'Corrección');

    expect($activo->refresh()->status)->toBe(FixedAssetStatus::Active)
        ->and($activo->accumulated()->toString())->toBe('2000.0000');
});

/*
|--------------------------------------------------------------------------
| Vista previa y aislamiento
|--------------------------------------------------------------------------
*/

it('muestra la vista previa sin escribir nada', function () {
    registerAsset($this);

    $previa = $this->depreciation->preview('2026-02-01');

    expect($previa)->toHaveCount(1)
        ->and($previa[0]['amount']->toString())->toBe('1000.0000')
        ->and(DepreciationRun::query()->count())->toBe(0);
});

it('aísla los activos entre empresas', function () {
    registerAsset($this);

    $otra = accountingCompany();
    actingAsUserOf($otra, role: PermissionCatalog::ACCOUNTANT);

    expect(FixedAsset::query()->count())->toBe(0)
        ->and(acrossCompanies(fn () => FixedAsset::acrossCompanies()->count()))->toBe(1);
});
