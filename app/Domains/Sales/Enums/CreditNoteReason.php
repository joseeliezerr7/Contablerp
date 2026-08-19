<?php

declare(strict_types=1);

namespace App\Domains\Sales\Enums;

/**
 * Motivo de la nota de crédito.
 *
 * No es decorativo: la devolución reingresa mercadería al inventario y las otras
 * dos no. Un descuento posterior o una corrección de precio mueven dinero, no
 * cajas.
 */
enum CreditNoteReason: string
{
    case Return = 'return';
    case Discount = 'discount';
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::Return => 'Devolución de mercadería',
            self::Discount => 'Descuento o rebaja posterior',
            self::Correction => 'Corrección de la factura',
        };
    }

    /**
     * Si el motivo implica que la mercadería vuelve a la bodega.
     */
    public function movesStock(): bool
    {
        return $this === self::Return;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
