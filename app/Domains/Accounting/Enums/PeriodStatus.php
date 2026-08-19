<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Enums;

enum PeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierto',
            self::Closed => 'Cerrado',
            self::Locked => 'Bloqueado',
        };
    }

    public function acceptsPostings(): bool
    {
        return $this === self::Open;
    }

    /**
     * Un período cerrado puede reabrirlo el contador; uno bloqueado no, porque
     * ya fue declarado a la autoridad fiscal o auditado.
     */
    public function canReopen(): bool
    {
        return $this === self::Closed;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Open => 'bg-emerald-50 text-emerald-700',
            self::Closed => 'bg-slate-100 text-slate-600',
            self::Locked => 'bg-red-50 text-red-700',
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
