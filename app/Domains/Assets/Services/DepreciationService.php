<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services;

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Accounting\Services\DocumentSeriesService;
use App\Domains\Assets\Enums\FixedAssetStatus;
use App\Domains\Assets\Exceptions\AssetException;
use App\Domains\Assets\Models\DepreciationRun;
use App\Domains\Assets\Models\DepreciationRunLine;
use App\Domains\Assets\Models\FixedAsset;
use App\Domains\Identity\Services\AuditLogger;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Depreciación en línea recta, por corridas mensuales.
 *
 * ## Por qué una corrida y no un cálculo al vuelo
 *
 * La depreciación produce una partida contable, y una partida no puede depender
 * de cuándo se abra una pantalla. La corrida congela lo que le tocó a cada
 * activo ese mes, genera **una sola partida** por corrida —agrupada por cuenta,
 * no una por activo— y deja el rastro para auditarla.
 *
 * ## La regla que evita el centavo perdido
 *
 * La cuota mensual es `(costo − residual) / vida útil`, y ese cociente casi
 * nunca es exacto: 10 000 entre 36 meses da 277.7778. Aplicar la cuota 36 veces
 * dejaría el activo unos centavos por encima o por debajo del residual.
 *
 * Por eso **el último mes se lleva el resto**: si lo que queda por depreciar es
 * menor que la cuota, se deprecia lo que queda y el activo pasa a «totalmente
 * depreciado» con su valor en libros exactamente en el residual. Es el mismo
 * criterio que el kardex usa al despachar la última unidad.
 */
final class DepreciationService
{
    public const SERIES = 'depreciation_run';

    public function __construct(
        private readonly CompanyContext $context,
        private readonly AccountingEngine $engine,
        private readonly DocumentSeriesService $series,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Ejecuta la depreciación de un mes.
     */
    public function run(DateTimeInterface|string $month, ?string $notes = null): DepreciationRun
    {
        $period = CarbonImmutable::parse($month)->startOfMonth();

        // Se comprueba antes de mirar los activos. Si no, al pedir de nuevo un
        // mes ya corrido se contestaría «no hay activos que depreciar» —cierto,
        // porque ya se depreciaron— y el usuario no entendería por qué. El
        // índice único sigue siendo la garantía real; esto es para que el
        // mensaje diga lo que pasa.
        $this->guardPeriodNotRun($period);

        return DB::transaction(function () use ($period, $notes): DepreciationRun {
            $assets = FixedAsset::query()
                ->with('category')
                ->depreciable()
                ->orderBy('code')
                ->get()
                ->filter(fn (FixedAsset $asset) => $asset->depreciatesIn($period))
                ->values();

            if ($assets->isEmpty()) {
                throw AssetException::nothingToDepreciate($period);
            }

            $run = new DepreciationRun;

            try {
                $run->forceFill([
                    'company_id' => $this->context->idOrFail(),
                    'number' => $this->series->next(self::SERIES, '*', null, 'DEP-'),
                    'period_month' => $period->toDateString(),
                    'posted_on' => $period->endOfMonth()->toDateString(),
                    'status' => 'posted',
                    'notes' => $notes,
                    'created_by' => Auth::id(),
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw AssetException::periodAlreadyRun($period);
            }

            $total = Money::zero();
            $byAccounts = [];

            foreach ($assets as $asset) {
                $amount = $this->quotaFor($asset);

                if (! $amount->isPositive()) {
                    continue;
                }

                $accumulated = $asset->accumulated()->plus($amount);
                $bookValue = $asset->costAmount()->minus($accumulated);

                $asset->forceFill([
                    'accumulated_depreciation' => $accumulated->toString(),
                    'depreciated_through' => $period->toDateString(),
                    // Si ya llegó al residual, deja de depreciar: sigue en uso y
                    // en el balance, pero no genera más gasto.
                    'status' => $bookValue->minus($asset->salvageValue())->isPositive()
                        ? FixedAssetStatus::Active
                        : FixedAssetStatus::FullyDepreciated,
                ])->save();

                $line = new DepreciationRunLine;
                $line->forceFill([
                    'depreciation_run_id' => $run->id,
                    'company_id' => $run->company_id,
                    'fixed_asset_id' => $asset->id,
                    'amount' => $amount->toString(),
                    'accumulated_after' => $accumulated->toString(),
                    'book_value_after' => $bookValue->toString(),
                ])->save();

                $expense = $asset->category->depreciation_account_id;
                $accrued = $asset->category->accumulated_account_id;

                $byAccounts[$expense]['debit'] = ($byAccounts[$expense]['debit'] ?? Money::zero())->plus($amount);
                $byAccounts[$accrued]['credit'] = ($byAccounts[$accrued]['credit'] ?? Money::zero())->plus($amount);

                $total = $total->plus($amount);
            }

            $run->forceFill([
                'total' => $total->toString(),
                'asset_count' => $run->lines()->count(),
            ])->save();

            if ($total->isPositive()) {
                $this->engine->post($this->buildJournalDraft($run, $byAccounts));
            }

            $this->audit->log('posted', $run, newValues: [
                'number' => $run->number,
                'period' => $period->format('Y-m'),
                'total' => $total->toString(),
            ], module: 'assets');

            return $run->refresh();
        });
    }

    /**
     * Anula una corrida: revierte la partida y devuelve a cada activo el
     * acumulado que tenía antes.
     */
    public function void(DepreciationRun $run, string $reason): DepreciationRun
    {
        if ($run->isVoided()) {
            throw AssetException::runVoided($run);
        }

        if (trim($reason) === '') {
            throw new AssetException('Hay que indicar el motivo de la anulación.');
        }

        // Deshacer un mes intermedio dejaría los acumulados de los meses
        // posteriores apoyados en un número que ya no existe.
        $laterExists = DepreciationRun::query()
            ->where('status', 'posted')
            ->where('period_month', '>', $run->period_month->toDateString())
            ->exists();

        if ($laterExists) {
            throw AssetException::laterRunExists($run);
        }

        return DB::transaction(function () use ($run, $reason): DepreciationRun {
            $run->load('lines.asset');

            $entry = $run->journalEntry();

            if ($entry !== null) {
                $this->voidOrReverse($entry, $reason);
            }

            $previousMonth = CarbonImmutable::parse($run->period_month)->subMonth()->startOfMonth();

            foreach ($run->lines as $line) {
                $asset = $line->asset;

                $accumulated = $asset->accumulated()->minus($line->amountMoney());

                $asset->forceFill([
                    'accumulated_depreciation' => $accumulated->toString(),
                    // Vuelve a estar activo: la razón de anular es rehacer el mes.
                    'status' => $asset->isDisposed()
                        ? $asset->status
                        : FixedAssetStatus::Active,
                    'depreciated_through' => $this->hasEarlierRunFor($asset, $previousMonth)
                        ? $previousMonth->toDateString()
                        : null,
                ])->save();
            }

            $run->forceFill([
                'status' => 'voided',
                'void_reason' => $reason,
                'voided_at' => now(),
                'voided_by' => Auth::id(),
            ])->save();

            $this->audit->log('voided', $run, reason: $reason, module: 'assets');

            return $run->refresh();
        });
    }

    /**
     * Lo que se depreciaría en un mes, sin escribir nada.
     *
     * Alimenta la vista previa: el contador ve el detalle antes de generar la
     * partida.
     *
     * @return array<int, array{asset: FixedAsset, amount: Money}>
     */
    public function preview(DateTimeInterface|string $month): array
    {
        $period = CarbonImmutable::parse($month)->startOfMonth();

        return FixedAsset::query()
            ->with('category')
            ->depreciable()
            ->orderBy('code')
            ->get()
            ->filter(fn (FixedAsset $asset) => $asset->depreciatesIn($period))
            ->map(fn (FixedAsset $asset) => [
                'asset' => $asset,
                'amount' => $this->quotaFor($asset),
            ])
            ->filter(fn (array $row) => $row['amount']->isPositive())
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Interno
    |--------------------------------------------------------------------------
    */

    /**
     * Cuota del mes: la mensual, salvo que quede menos por depreciar.
     */
    private function quotaFor(FixedAsset $asset): Money
    {
        $quota = $asset->monthlyQuota();
        $remaining = $asset->remainingDepreciable();

        return $quota->greaterThan($remaining) ? $remaining : $quota;
    }

    private function guardPeriodNotRun(CarbonImmutable $period): void
    {
        $exists = DepreciationRun::query()
            ->where('status', 'posted')
            ->whereDate('period_month', $period->toDateString())
            ->exists();

        if ($exists) {
            throw AssetException::periodAlreadyRun($period);
        }
    }

    /**
     * Si el activo ya tenía una corrida en el mes anterior al que se anula.
     */
    private function hasEarlierRunFor(FixedAsset $asset, DateTimeInterface $month): bool
    {
        return DepreciationRunLine::query()
            ->where('fixed_asset_id', $asset->id)
            ->whereHas('run', fn ($q) => $q
                ->where('status', 'posted')
                ->whereDate('period_month', '<=', CarbonImmutable::parse($month)->toDateString()))
            ->exists();
    }

    /**
     * Una partida por corrida, agrupada por cuenta.
     *
     * Una partida por activo llenaría el libro diario de folios de doce
     * lempiras y haría ilegible el mes. El detalle por activo vive en las
     * líneas de la corrida, que es donde se consulta.
     *
     * @param  array<int, array{debit?: Money, credit?: Money}>  $byAccounts
     */
    private function buildJournalDraft(DepreciationRun $run, array $byAccounts): JournalDraft
    {
        $draft = JournalDraft::on(
            $run->posted_on,
            'Depreciación de '.$run->period_month->translatedFormat('F \d\e Y'),
        )
            ->withReference($run->number)
            ->fromDocument(DepreciationRun::SOURCE_TYPE, $run->id);

        foreach ($byAccounts as $accountId => $sides) {
            if (isset($sides['debit']) && $sides['debit']->isPositive()) {
                $draft->debit($accountId, $sides['debit'], 'Gasto por depreciación');
            }

            if (isset($sides['credit']) && $sides['credit']->isPositive()) {
                $draft->credit($accountId, $sides['credit'], 'Depreciación acumulada');
            }
        }

        return $draft;
    }

    private function voidOrReverse(JournalEntry $entry, string $reason): void
    {
        if ($entry->period->acceptsPostings()) {
            $this->engine->void($entry, $reason);

            return;
        }

        $this->engine->reverse($entry, $reason);
    }
}
