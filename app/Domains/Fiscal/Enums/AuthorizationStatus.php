<?php

declare(strict_types=1);

namespace App\Domains\Fiscal\Enums;

/**
 * Estado de una autorización de impresión.
 *
 * `exhausted` y `expired` se distinguen a propósito: la primera se acabó porque
 * se usó, la segunda porque pasó la fecha. Al contribuyente le importa la
 * diferencia —una dice que vendió, la otra que se le olvidó renovar— y a la hora
 * de pedir la siguiente autorización no se piden los mismos rangos.
 */
enum AuthorizationStatus: string
{
    case Active = 'active';
    case Exhausted = 'exhausted';
    case Expired = 'expired';
    case Replaced = 'replaced';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Vigente',
            self::Exhausted => 'Agotada',
            self::Expired => 'Vencida',
            self::Replaced => 'Reemplazada',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Active => 'bg-emerald-50 text-emerald-700',
            self::Exhausted => 'bg-slate-100 text-slate-600',
            self::Expired => 'bg-red-50 text-red-700',
            self::Replaced => 'bg-slate-100 text-slate-600',
        };
    }

    public function canEmit(): bool
    {
        return $this === self::Active;
    }
}
