<?php

declare(strict_types=1);

namespace App\Domains\Treasury\Enums;

enum ReconciliationStatus: string
{
    case Draft = 'draft';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'En proceso',
            self::Closed => 'Cerrada',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-amber-50 text-amber-700',
            self::Closed => 'bg-emerald-50 text-emerald-700',
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
