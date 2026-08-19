<?php

declare(strict_types=1);

namespace App\Domains\Treasury\Enums;

enum CashSessionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierta',
            self::Closed => 'Cerrada',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Open => 'bg-emerald-50 text-emerald-700',
            self::Closed => 'bg-slate-100 text-slate-700',
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
