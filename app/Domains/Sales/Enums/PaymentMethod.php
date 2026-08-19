<?php

declare(strict_types=1);

namespace App\Domains\Sales\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Check = 'check';
    case Transfer = 'transfer';
    case Card = 'card';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::Check => 'Cheque',
            self::Transfer => 'Transferencia',
            self::Card => 'Tarjeta',
            self::Other => 'Otro',
        };
    }

    /**
     * Los medios que exigen anotar un número: sin él, la conciliación bancaria
     * de la Fase 6 no puede casar el movimiento.
     */
    public function requiresReference(): bool
    {
        return in_array($this, [self::Check, self::Transfer], strict: true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
