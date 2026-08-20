<?php

declare(strict_types=1);

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Assets\Enums\FixedAssetStatus;
use App\Domains\Assets\Exceptions\AssetException;
use App\Domains\Assets\Models\DepreciationRun;
use App\Domains\Assets\Models\DepreciationRunLine;
use App\Domains\Assets\Models\FixedAsset;
use App\Domains\Assets\Models\FixedAssetCategory;
use App\Domains\Assets\Services\DepreciationService;
use App\Domains\Assets\Services\FixedAssetService;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Criterio de aceptación: **la depreciación acumulada que llevan
 * los activos tiene que ser la que dice el libro.**
 *
 * Son dos registros de lo mismo por caminos distintos: el acumulado que guarda
 * cada activo, y el saldo de la cuenta de depreciación acumulada que escribe el
 * motor contable. El acumulado del activo se guarda a propósito —la corrida
 * mensual necesita saber cuánto lleva cada uno sin recorrer la historia—, y ese
 * duplicado es exactamente lo que esta prueba vigila.
 *
 * Además comprueba las dos reglas que evitan errores de centavos: ningún activo
 * baja de su valor residual, y la suma de las líneas de las corridas coincide
 * con el acumulado.
 */
beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();

    $this->assets = app(FixedAssetService::class);
    $this->depreciation = app(DepreciationService::class);
    $this->branch = mainBranch();

    // El vehículo se deprecia en 60 meses: la prueba llega hasta 2031.
    openFiscalYears(2027, 2028, 2029, 2030, 2031);
});

/**
 * Categoría con sus tres cuentas.
 */
function categoryFor(string $code, string $asset, string $accumulated): FixedAssetCategory
{
    $category = new FixedAssetCategory;
    $category->forceFill([
        'company_id' => app(CompanyContext::class)->idOrFail(),
        'code' => $code,
        'name' => 'Categoría '.$code,
        'useful_life_months' => 36,
        'asset_account_id' => account($asset)->id,
        'depreciation_account_id' => account('6.1.06')->id,
        'accumulated_account_id' => account($accumulated)->id,
        'is_active' => true,
    ])->save();

    return $category;
}

/**
 * Da de alta activos de dos categorías distintas, con costos que no reparten,
 * y corre varios meses.
 */
function exerciseDepreciation(object $ctx, int $months = 6): void
{
    $computo = categoryFor('COMP', '1.2.01.04', '1.2.02.03');
    $vehiculos = categoryFor('VEH', '1.2.01.05', '1.2.02.04');

    // La compra del activo es la que lo mete al balance. Darlo de alta en el
    // módulo no asienta nada: solo declara que hay que depreciarlo. Aquí se
    // registra la compra a mano para que el libro refleje la realidad, que es
    // lo que ocurre cuando el activo entra por una factura de compra.
    $register = function (string $code, int $categoryId, string $cost, string $salvage, int $life, string $acquired) use ($ctx) {
        $category = FixedAssetCategory::query()->findOrFail($categoryId);

        app(AccountingEngine::class)->post(
            JournalDraft::on($acquired, 'Compra de activo fijo '.$code)
                ->debit($category->asset_account_id, $cost)
                ->credit(account('1.1.02.01')->id, $cost)
        );

        return $ctx->assets->create([
            'branch_id' => $ctx->branch->id,
            'fixed_asset_category_id' => $categoryId,
            'code' => $code,
            'name' => 'Activo '.$code,
            'acquired_on' => $acquired,
            'cost' => $cost,
            'salvage_value' => $salvage,
            'useful_life_months' => $life,
        ]);
    };

    // Costos que no reparten en partes iguales.
    $register('AF-001', $computo->id, '33333.33', '0', 36, '2026-01-10');
    $register('AF-002', $computo->id, '7777.77', '777.77', 24, '2026-01-20');
    $register('AF-003', $vehiculos->id, '450000.00', '50000.00', 60, '2026-02-05');
    // Vida corta: se agota dentro del rango y deja de depreciar.
    $register('AF-004', $computo->id, '3000.00', '0', 3, '2026-01-31');

    for ($i = 0; $i < $months; $i++) {
        try {
            $ctx->depreciation->run(CarbonImmutable::parse('2026-02-01')->addMonths($i));
        } catch (AssetException) {
            // Un mes sin nada que depreciar no es un fallo.
        }
    }
}

/**
 * Saldo acreedor de una cuenta: haber menos debe.
 */
function creditBalanceOf(string $code): Money
{
    return ledgerBalanceOf($code)->negated();
}

it('cuadra el acumulado de los activos contra sus cuentas contables', function () {
    exerciseDepreciation($this);

    $porCuenta = FixedAsset::query()
        ->with('category')
        ->get()
        ->groupBy(fn (FixedAsset $asset) => $asset->category->accumulated_account_id);

    expect($porCuenta)->not->toBeEmpty();

    foreach ($porCuenta as $accountId => $assets) {
        $enActivos = Money::sum($assets->map(fn (FixedAsset $a) => $a->accumulated())->all());

        $code = Account::query()->findOrFail($accountId)->code;
        $enLibro = creditBalanceOf($code);

        expect($enActivos->equals($enLibro))->toBeTrue(
            "La cuenta {$code} dice {$enLibro->format()} y los activos {$enActivos->format()}."
        );
    }
});

it('cuadra el gasto por depreciación contra el total de las corridas', function () {
    exerciseDepreciation($this);

    $enCorridas = Money::sum(
        DepreciationRunLine::query()
            ->whereHas('run', fn ($q) => $q->where('status', 'posted'))
            ->get()
            ->map(fn (DepreciationRunLine $l) => $l->amountMoney())
            ->all()
    );

    expect($enCorridas->isPositive())->toBeTrue('La prueba no depreció nada')
        ->and(ledgerBalanceOf('6.1.06')->equals($enCorridas))->toBeTrue();
});

it('cuadra activo por activo contra sus líneas de corrida', function () {
    exerciseDepreciation($this);

    foreach (FixedAsset::query()->get() as $asset) {
        $enLineas = Money::sum(
            $asset->depreciationLines()
                ->whereHas('run', fn ($q) => $q->where('status', 'posted'))
                ->get()
                ->map(fn (DepreciationRunLine $l) => $l->amountMoney())
                ->all()
        );

        expect($asset->accumulated()->equals($enLineas))->toBeTrue(
            "{$asset->code}: acumulado {$asset->accumulated()->format()}, líneas {$enLineas->format()}."
        );
    }
});

it('no deja ningún activo por debajo de su valor residual', function () {
    exerciseDepreciation($this, months: 70);

    foreach (FixedAsset::query()->get() as $asset) {
        expect($asset->bookValue()->compareTo($asset->salvageValue()))->toBeGreaterThanOrEqual(
            0,
            "{$asset->code} quedó en {$asset->bookValue()->format()}, por debajo de su residual {$asset->salvageValue()->format()}."
        );
    }
});

it('deja en el residual exacto a los activos que agotaron su vida', function () {
    exerciseDepreciation($this, months: 70);

    $agotados = FixedAsset::query()->where('status', FixedAssetStatus::FullyDepreciated)->get();

    expect($agotados)->not->toBeEmpty();

    foreach ($agotados as $asset) {
        expect($asset->bookValue()->equals($asset->salvageValue()))->toBeTrue(
            "{$asset->code} quedó en {$asset->bookValue()->format()} y su residual es {$asset->salvageValue()->format()}."
        );
    }
});

it('vuelve a cuadrar después de anular la última corrida', function () {
    exerciseDepreciation($this, months: 5);

    $ultima = DepreciationRun::query()
        ->where('status', 'posted')
        ->orderByDesc('period_month')
        ->firstOrFail();

    $this->depreciation->void($ultima, 'Anulada en la prueba de invariante');

    $enActivos = Money::sum(
        FixedAsset::query()->get()->map(fn (FixedAsset $a) => $a->accumulated())->all()
    );

    $enLibro = creditBalanceOf('1.2.02.03')->plus(creditBalanceOf('1.2.02.04'));

    expect($enActivos->equals($enLibro))->toBeTrue(
        "Tras anular: libro {$enLibro->format()}, activos {$enActivos->format()}."
    );
});

it('mantiene el libro cuadrado con la depreciación en marcha', function () {
    exerciseDepreciation($this);

    $totales = DB::table('journal_entry_lines as l')
        ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
        ->where('e.status', JournalEntryStatus::Posted->value)
        ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
        ->first();

    expect(Money::of((string) $totales->debit)->equals(Money::of((string) $totales->credit)))->toBeTrue();
});

it('saca del balance el activo dado de baja y reconoce el resultado', function () {
    exerciseDepreciation($this, months: 3);

    $activo = FixedAsset::query()->where('code', 'AF-001')->firstOrFail();
    $valorEnLibros = $activo->bookValue();

    // Se vende por menos de lo que vale: hay pérdida.
    $recibido = $valorEnLibros->minus(Money::of('5000.00'));

    $this->assets->dispose($activo, '2026-05-15', $recibido, 'Vendida a un empleado', account('1.1.02.01')->id);

    $activo->refresh();

    // Lo que debe quedar en la cuenta de activo: los otros dos de la misma
    // categoría, ya sin el que se dio de baja.
    $resto = Money::sum(
        FixedAsset::query()
            ->whereIn('code', ['AF-002', 'AF-004'])
            ->get()
            ->map(fn (FixedAsset $a) => $a->costAmount())
            ->all()
    );

    expect($activo->status)->toBe(FixedAssetStatus::Disposed)
        // La pérdida es la diferencia entre lo recibido y el valor en libros.
        ->and(ledgerBalanceOf('6.3.05')->toString())->toBe('5000.0000')
        // Del balance salió el costo completo del activo dado de baja.
        ->and(ledgerBalanceOf('1.2.01.04')->equals($resto))->toBeTrue();
});

it('deja la cuenta de activo cuadrada contra los activos vivos', function () {
    exerciseDepreciation($this, months: 3);

    $vivos = FixedAsset::query()
        ->with('category')
        ->where('status', '!=', FixedAssetStatus::Disposed)
        ->get()
        ->groupBy(fn (FixedAsset $a) => $a->category->asset_account_id);

    foreach ($vivos as $accountId => $assets) {
        $enActivos = Money::sum($assets->map(fn (FixedAsset $a) => $a->costAmount())->all());
        $code = Account::query()->findOrFail($accountId)->code;

        $enLibro = ledgerBalanceOf($code);

        expect($enLibro->equals($enActivos))->toBeTrue(
            "La cuenta {$code} dice {$enLibro->format()} y los activos {$enActivos->format()}."
        );
    }
});
