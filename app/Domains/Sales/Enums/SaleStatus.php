<?php

declare(strict_types=1);

namespace App\Domains\Sales\Enums;

enum SaleStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Issued => 'Emitida',
            self::Voided => 'Anulada',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Solo la factura emitida existe para la contabilidad y para el cliente.
     */
    public function isEffective(): bool
    {
        return $this === self::Issued;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-amber-50 text-amber-700',
            self::Issued => 'bg-emerald-50 text-emerald-700',
            self::Voided => 'bg-red-50 text-red-700',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
