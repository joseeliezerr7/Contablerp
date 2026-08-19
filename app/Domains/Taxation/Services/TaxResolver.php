<?php

declare(strict_types=1);

namespace App\Domains\Taxation\Services;

use App\Domains\Taxation\DataTransfer\LineCalculation;
use App\Domains\Taxation\Models\Tax;
use App\Support\Money;

/**
 * Cálculo de impuestos y descuentos de una línea.
 *
 * Redondea el impuesto a dos decimales **por línea**, no al final. Es lo que
 * hace que el total impreso coincida con la suma de las líneas impresas: si se
 * redondeara solo el total, un cliente que sume la columna a mano encontraría
 * un centavo de diferencia y con razón desconfiaría de la factura.
 */
final class TaxResolver
{
    /**
     * @param  string  $quantity  Cantidad, hasta seis decimales.
     * @param  string  $unitPrice  Precio unitario, hasta seis decimales.
     * @param  string  $discountRate  Porcentaje de descuento (0 a 100).
     */
    public function calculateLine(
        string $quantity,
        string $unitPrice,
        string $discountRate = '0',
        ?Tax $tax = null,
        int $decimals = 2,
    ): LineCalculation {
        $gross = Money::of($unitPrice)->times($quantity)->round($decimals);

        $discountAmount = $this->isZero($discountRate)
            ? Money::zero()
            : $gross->percent($discountRate)->round($decimals);

        $net = $gross->minus($discountAmount);

        if ($tax === null || $tax->isZeroRated()) {
            return new LineCalculation(
                gross: $gross,
                discountAmount: $discountAmount,
                subtotal: $net,
                taxAmount: Money::zero(),
                total: $net,
                taxRate: '0',
            );
        }

        $rate = (string) $tax->rate;

        if ($tax->is_included) {
            // El precio ya trae el impuesto, así que se despeja la base:
            //   base = neto / (1 + tasa/100)
            $divisor = bcadd('1', bcdiv($rate, '100', 10), 10);
            $base = $net->dividedBy($divisor)->round($decimals);

            return new LineCalculation(
                gross: $gross,
                discountAmount: $discountAmount,
                subtotal: $base,
                // Por diferencia y no recalculado: así base + impuesto da
                // exactamente el precio que el cliente ve en la etiqueta.
                taxAmount: $net->minus($base),
                total: $net,
                taxRate: $rate,
            );
        }

        $taxAmount = $net->percent($rate)->round($decimals);

        return new LineCalculation(
            gross: $gross,
            discountAmount: $discountAmount,
            subtotal: $net,
            taxAmount: $taxAmount,
            total: $net->plus($taxAmount),
            taxRate: $rate,
        );
    }

    /**
     * Suma de varias líneas ya calculadas.
     *
     * @param  array<int, LineCalculation>  $lines
     * @return array{subtotal: Money, discount: Money, tax: Money, total: Money}
     */
    public function totals(array $lines): array
    {
        return [
            'subtotal' => Money::sum(array_map(fn (LineCalculation $l) => $l->subtotal, $lines)),
            'discount' => Money::sum(array_map(fn (LineCalculation $l) => $l->discountAmount, $lines)),
            'tax' => Money::sum(array_map(fn (LineCalculation $l) => $l->taxAmount, $lines)),
            'total' => Money::sum(array_map(fn (LineCalculation $l) => $l->total, $lines)),
        ];
    }

    private function isZero(string $value): bool
    {
        return bccomp($value, '0', 6) === 0;
    }
}
