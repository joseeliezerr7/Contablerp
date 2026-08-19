<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * Estado de la suscripción.
 *
 * `past_due` y `suspended` son distintos a propósito: en el primero el cliente
 * debe una factura pero sigue trabajando —cortarle el acceso el día que vence
 * es la forma más rápida de perderlo—, y en el segundo ya se le cortó. Entre
 * los dos hay una decisión comercial, no automática.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'En prueba',
            self::Active => 'Activa',
            self::PastDue => 'Con pago pendiente',
            self::Suspended => 'Suspendida',
            self::Cancelled => 'Cancelada',
        };
    }

    /**
     * Si el tenant puede seguir usando el sistema.
     */
    public function allowsAccess(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], strict: true);
    }

    /**
     * Si sigue contando como cliente para las métricas de ingreso.
     */
    public function isBillable(): bool
    {
        return in_array($this, [self::Active, self::PastDue], strict: true);
    }

    public function isLive(): bool
    {
        return $this !== self::Cancelled;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Trialing => 'bg-sky-50 text-sky-700',
            self::Active => 'bg-emerald-50 text-emerald-700',
            self::PastDue => 'bg-amber-50 text-amber-700',
            self::Suspended => 'bg-red-50 text-red-700',
            self::Cancelled => 'bg-slate-100 text-slate-600',
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
