<?php

declare(strict_types=1);

namespace App\Domains\Sales\Enums;

enum PaymentCondition: string
{
    case Cash = 'cash';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Contado',
            self::Credit => 'Crédito',
        };
    }

    /**
     * Solo las ventas al crédito abren una cuenta por cobrar; las de contado
     * entran directo a caja o banco.
     */
    public function createsReceivable(): bool
    {
        return $this === self::Credit;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
