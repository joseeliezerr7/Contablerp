<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services;

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Enums\AccountMappingKey;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Accounting\Services\AccountMappingService;
use App\Domains\Assets\Enums\FixedAssetStatus;
use App\Domains\Assets\Exceptions\AssetException;
use App\Domains\Assets\Models\FixedAsset;
use App\Domains\Assets\Models\FixedAssetCategory;
use App\Domains\Identity\Services\AuditLogger;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Alta y baja de activos fijos.
 *
 * ## El alta no genera partida
 *
 * Comprar un activo ya se contabilizó: fue una compra, y su línea fue a la
 * cuenta de activo correspondiente. Darlo de alta aquí es decirle al sistema
 * que ese importe hay que depreciarlo, no volver a registrarlo. Si el alta
 * asentara algo, el activo aparecería dos veces en el balance.
 *
 * ## La baja sí, y es la parte interesante
 *
 * Dar de baja saca del balance el costo y su depreciación acumulada, y la
 * diferencia contra lo que se recibió es una ganancia o una pérdida:
 *
 *     D  Depreciación acumulada   (lo depreciado hasta hoy)
 *     D  Caja o banco             (lo que se recibió, si se vendió)
 *     D  Pérdida en baja          (si se recibió menos que el valor en libros)
 *         C  Activo fijo          (el costo completo)
 *         C  Ganancia en baja     (si se recibió más)
 *
 * Un activo descartado sin recibir nada es el mismo asiento con cero en caja:
 * toda la pérdida es el valor en libros que quedaba.
 */
final class FixedAssetService
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly AccountingEngine $engine,
        private readonly AccountMappingService $mappings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): FixedAsset
    {
        return DB::transaction(function () use ($data): FixedAsset {
            $attributes = $this->attributes($data);

            $asset = new FixedAsset;
            $asset->forceFill([
                ...$attributes,
                'company_id' => $this->context->idOrFail(),
                'accumulated_depreciation' => '0',
                'status' => FixedAssetStatus::Active,
                'created_by' => Auth::id(),
            ])->save();

            $this->audit->log('created', $asset, newValues: [
                'code' => $asset->code,
                'cost' => $asset->cost,
            ], module: 'assets');

            return $asset->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FixedAsset $asset, array $data): FixedAsset
    {
        if ($asset->isDisposed()) {
            throw AssetException::assetDisposed($asset);
        }

        return DB::transaction(function () use ($asset, $data): FixedAsset {
            $asset->forceFill($this->attributes($data))->save();

            return $asset->refresh();
        });
    }

    public function delete(FixedAsset $asset): void
    {
        DB::transaction(function () use ($asset): void {
            $asset->delete();
        });
    }

    /**
     * Da de baja el activo y contabiliza el resultado.
     *
     * @param  Money  $proceeds  Lo que se recibió. Cero si se descartó.
     */
    public function dispose(
        FixedAsset $asset,
        DateTimeInterface|string $on,
        Money $proceeds,
        string $reason,
        ?int $proceedsAccountId = null,
    ): FixedAsset {
        if ($asset->isDisposed()) {
            throw AssetException::assetDisposed($asset);
        }

        if (trim($reason) === '') {
            throw new AssetException('Hay que indicar el motivo de la baja.');
        }

        $date = CarbonImmutable::parse($on)->startOfDay();

        if ($date->lt(CarbonImmutable::parse($asset->acquired_on)->startOfDay())) {
            throw AssetException::disposalBeforeAcquisition($asset);
        }

        return DB::transaction(function () use ($asset, $date, $proceeds, $reason, $proceedsAccountId): FixedAsset {
            $asset->loadMissing('category');

            $this->engine->post(
                $this->buildDisposalDraft($asset, $date, $proceeds, $proceedsAccountId)
            );

            $asset->forceFill([
                'status' => FixedAssetStatus::Disposed,
                'disposed_on' => $date->toDateString(),
                'disposal_amount' => $proceeds->toString(),
                'disposal_reason' => $reason,
            ])->save();

            $this->audit->log('disposed', $asset, reason: $reason, newValues: [
                'disposed_on' => $date->toDateString(),
                'proceeds' => $proceeds->toString(),
                'book_value' => $asset->book_value,
            ], module: 'assets');

            return $asset->refresh();
        });
    }

    /**
     * Resultado de dar de baja hoy: positivo ganancia, negativo pérdida.
     */
    public function disposalResult(FixedAsset $asset, Money $proceeds): Money
    {
        return $proceeds->minus($asset->bookValue());
    }

    /*
    |--------------------------------------------------------------------------
    | Interno
    |--------------------------------------------------------------------------
    */

    private function buildDisposalDraft(
        FixedAsset $asset,
        CarbonImmutable $date,
        Money $proceeds,
        ?int $proceedsAccountId,
    ): JournalDraft {
        $bookValue = $asset->bookValue();
        $result = $proceeds->minus($bookValue);

        $draft = JournalDraft::on($date, 'Baja de activo fijo '.$asset->label())
            ->inBranch($asset->branch_id)
            ->withReference($asset->code)
            ->fromDocument(FixedAsset::SOURCE_TYPE, $asset->id);

        // Sale la depreciación acumulada que llevaba encima.
        if ($asset->accumulated()->isPositive()) {
            $draft->debit(
                $asset->category->accumulated_account_id,
                $asset->accumulated(),
                'Depreciación acumulada del activo',
            );
        }

        // Entra lo que se haya recibido.
        if ($proceeds->isPositive()) {
            $draft->debit(
                $proceedsAccountId ?? $this->mappings->resolveId(AccountMappingKey::TreasuryBankDefault),
                $proceeds,
                'Producto de la venta del activo',
            );
        }

        if ($result->isNegative()) {
            $draft->debit(
                $this->mappings->resolveId(AccountMappingKey::AssetsDisposalLoss),
                $result->absolute(),
                'Pérdida en baja de activo fijo',
            );
        }

        // Sale el activo por su costo completo.
        $draft->credit($asset->category->asset_account_id, $asset->costAmount(), 'Costo del activo dado de baja');

        if ($result->isPositive()) {
            $draft->credit(
                $this->mappings->resolveId(AccountMappingKey::AssetsDisposalGain),
                $result,
                'Ganancia en baja de activo fijo',
            );
        }

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $cost = Money::of((string) $data['cost']);
        $salvage = Money::of((string) ($data['salvage_value'] ?? '0'));

        if (! $cost->isPositive()) {
            throw AssetException::invalidCost();
        }

        if ($salvage->greaterThan($cost)) {
            throw AssetException::salvageAboveCost();
        }

        $category = FixedAssetCategory::query()->findOrFail($data['fixed_asset_category_id']);

        $life = (int) ($data['useful_life_months'] ?? $category->useful_life_months);

        if ($life < 1) {
            throw AssetException::invalidUsefulLife();
        }

        return [
            'branch_id' => $data['branch_id'] ?? null,
            'fixed_asset_category_id' => $category->id,
            'code' => trim((string) $data['code']),
            'name' => trim((string) $data['name']),
            'description' => $data['description'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'location' => $data['location'] ?? null,
            'acquired_on' => CarbonImmutable::parse($data['acquired_on'])->toDateString(),
            'cost' => $cost->toString(),
            'salvage_value' => $salvage->toString(),
            'useful_life_months' => $life,
            'method' => 'straight_line',
        ];
    }
}
