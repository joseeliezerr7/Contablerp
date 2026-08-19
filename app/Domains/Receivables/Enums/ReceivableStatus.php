<?php

declare(strict_types=1);

namespace App\Domains\Receivables\Enums;

enum ReceivableStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Pendiente',
            self::Paid => 'Cancelada',
            self::Voided => 'Anulada',
        };
    }

    public function isOutstanding(): bool
    {
        return $this === self::Open;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Open => 'bg-amber-50 text-amber-700',
            self::Paid => 'bg-emerald-50 text-emerald-700',
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
