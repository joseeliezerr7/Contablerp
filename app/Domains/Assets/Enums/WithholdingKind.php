<?php

declare(strict_types=1);

namespace App\Domains\Assets\Enums;

enum WithholdingKind: string
{
    case IncomeTax = 'income_tax';
    case SalesTax = 'sales_tax';

    public function label(): string
    {
        return match ($this) {
            self::IncomeTax => 'ISR',
            self::SalesTax => 'ISV retenido',
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
