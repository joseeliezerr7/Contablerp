<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services;

use App\Domains\Assets\Models\Withholding;
use App\Domains\Assets\Models\WithholdingType;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Retenciones practicadas sobre un documento.
 *
 * No genera partida propia: las líneas de la retención van **dentro** de la
 * partida del pago o del recibo que la practicó. Tienen que ir ahí, porque el
 * asiento debe cuadrar en un solo documento: se cancela la deuda completa, sale
 * menos dinero del banco, y la diferencia es la retención.
 *
 * Este servicio calcula, persiste el rastro y devuelve las líneas que el
 * documento tiene que añadir a su asiento.
 */
final class WithholdingService
{
    public function __construct(private readonly CompanyContext $context) {}

    /**
     * Calcula las retenciones de un documento sin escribir nada.
     *
     * @param  array<int, array{withholding_type_id: int, base_amount?: string}>  $requested
     * @param  Money  $defaultBase  Base cuando la línea no trae la suya.
     * @return array<int, array{type: WithholdingType, base: Money, amount: Money}>
     */
    public function calculate(array $requested, Money $defaultBase): array
    {
        $result = [];

        foreach ($requested as $row) {
            $type = WithholdingType::query()->find($row['withholding_type_id'] ?? null);

            if ($type === null) {
                continue;
            }

            $base = isset($row['base_amount']) && $row['base_amount'] !== ''
                ? Money::of((string) $row['base_amount'])
                : $defaultBase;

            $amount = $type->compute($base);

            if (! $amount->isPositive()) {
                continue;
            }

            $result[] = ['type' => $type, 'base' => $base, 'amount' => $amount];
        }

        return $result;
    }

    /**
     * Guarda el rastro de las retenciones practicadas por un documento.
     *
     * @param  array<int, array{type: WithholdingType, base: Money, amount: Money}>  $computed
     */
    public function record(
        Model $document,
        string $sourceType,
        array $computed,
        DateTimeInterface|string $date,
    ): void {
        foreach ($computed as $row) {
            $withholding = new Withholding;
            $withholding->forceFill([
                'company_id' => $this->context->idOrFail(),
                'withholding_type_id' => $row['type']->id,
                'source_type' => $sourceType,
                'source_id' => $document->getKey(),
                'date' => CarbonImmutable::parse($date)->toDateString(),
                'base_amount' => $row['base']->toString(),
                // La tasa se congela: si mañana cambia, este documento sigue
                // mostrando la de hoy.
                'rate' => (string) $row['type']->rate,
                'amount' => $row['amount']->toString(),
            ])->save();
        }
    }

    /**
     * Borra el rastro de un documento anulado.
     *
     * La reversión contable la hace el documento; aquí solo se limpia el
     * detalle, que sin su documento no significa nada.
     */
    public function clearFor(string $sourceType, int $sourceId): void
    {
        DB::transaction(function () use ($sourceType, $sourceId): void {
            Withholding::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->delete();
        });
    }

    /**
     * Suma retenida por un documento.
     *
     * @param  array<int, array{type: WithholdingType, base: Money, amount: Money}>  $computed
     */
    public function total(array $computed): Money
    {
        return Money::sum(array_map(fn (array $row) => $row['amount'], $computed));
    }
}
