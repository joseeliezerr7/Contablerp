<?php

declare(strict_types=1);

namespace App\Domains\Taxation\DataTransfer;

use App\Support\Money;

/**
 * Resultado del cálculo de una línea de documento: base, descuento, impuesto y
 * total, ya redondeados como se van a imprimir.
 */
final readonly class LineCalculation
{
    public function __construct(
        public Money $gross,
        public Money $discountAmount,
        public Money $subtotal,
        public Money $taxAmount,
        public Money $total,
        public string $taxRate,
    ) {}

    public static function zero(): self
    {
        return new self(
            Money::zero(), Money::zero(), Money::zero(), Money::zero(), Money::zero(), '0',
        );
    }
}
