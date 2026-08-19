<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Enums;

enum FiscalYearStatus: string
{
    case Open = 'open';
    case Closing = 'closing';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierto',
            self::Closing => 'En cierre',
            self::Closed => 'Cerrado',
        };
    }

    public function acceptsPostings(): bool
    {
        return $this !== self::Closed;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
