<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Enums;

/**
 * Clasificación de la cuenta para el Estado de Flujo de Efectivo (Fase 2).
 * Se captura desde ya en el plan de cuentas para no tener que reclasificar
 * históricos después.
 */
enum CashFlowClass: string
{
    case Operating = 'operating';
    case Investing = 'investing';
    case Financing = 'financing';

    public function label(): string
    {
        return match ($this) {
            self::Operating => 'Operación',
            self::Investing => 'Inversión',
            self::Financing => 'Financiamiento',
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
