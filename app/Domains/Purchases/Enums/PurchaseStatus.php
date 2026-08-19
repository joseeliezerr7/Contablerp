<?php

declare(strict_types=1);

namespace App\Domains\Purchases\Enums;

enum PurchaseStatus: string
{
    case Draft = 'draft';
    case Received = 'received';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Received => 'Recibida',
            self::Voided => 'Anulada',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Solo la compra recibida existe para la contabilidad y para el inventario.
     */
    public function isEffective(): bool
    {
        return $this === self::Received;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-amber-50 text-amber-700',
            self::Received => 'bg-emerald-50 text-emerald-700',
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
