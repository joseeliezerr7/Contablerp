<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

/**
 * Estado común de los documentos de inventario: ajustes y traslados.
 *
 * Comparten enum porque comparten ciclo de vida exacto. Si más adelante el
 * traslado necesita un estado «en tránsito», se separan.
 */
enum StockDocumentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Posted => 'Aplicado',
            self::Voided => 'Anulado',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isEffective(): bool
    {
        return $this === self::Posted;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-amber-50 text-amber-700',
            self::Posted => 'bg-emerald-50 text-emerald-700',
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
