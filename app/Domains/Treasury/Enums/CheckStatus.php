<?php

declare(strict_types=1);

namespace App\Domains\Treasury\Enums;

/**
 * Vida de un cheque girado.
 *
 * La distinción que importa para la conciliación es `cleared`: hasta que el
 * banco lo paga, el cheque es un «cheque pendiente» que explica por qué el
 * extracto muestra más dinero del que la empresa cree tener.
 */
enum CheckStatus: string
{
    case Issued = 'issued';
    case Delivered = 'delivered';
    case Cleared = 'cleared';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Girado',
            self::Delivered => 'Entregado',
            self::Cleared => 'Cobrado',
            self::Voided => 'Anulado',
        };
    }

    /**
     * Un cheque pendiente es el que ya salió del libro pero el banco no ha
     * pagado. Un cheque anulado no pende de nada.
     */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::Issued, self::Delivered], strict: true);
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Issued => 'bg-slate-100 text-slate-700',
            self::Delivered => 'bg-amber-50 text-amber-700',
            self::Cleared => 'bg-emerald-50 text-emerald-700',
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
