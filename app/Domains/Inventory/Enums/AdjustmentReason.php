<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

enum AdjustmentReason: string
{
    case Count = 'count';
    case Damage = 'damage';
    case Loss = 'loss';
    case Expiry = 'expiry';
    case Opening = 'opening';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Count => 'Diferencia de conteo físico',
            self::Damage => 'Producto dañado',
            self::Loss => 'Faltante o robo',
            self::Expiry => 'Producto vencido',
            self::Opening => 'Carga de existencia inicial',
            self::Other => 'Otro motivo',
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
