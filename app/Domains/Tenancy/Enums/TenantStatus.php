<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Enums;

enum TenantStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Prueba',
            self::Active => 'Activa',
            self::Suspended => 'Suspendida',
            self::Cancelled => 'Cancelada',
        };
    }

    /**
     * Solo las cuentas en prueba o activas pueden operar el sistema.
     */
    public function allowsAccess(): bool
    {
        return in_array($this, [self::Trial, self::Active], strict: true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
