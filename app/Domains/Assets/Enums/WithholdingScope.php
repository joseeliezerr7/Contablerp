<?php

declare(strict_types=1);

namespace App\Domains\Assets\Enums;

/**
 * A quién se le practica la retención.
 *
 * En una compra retenemos nosotros al proveedor y le debemos ese importe al
 * fisco: es un pasivo. En una venta nos retiene el cliente y ese importe es un
 * impuesto pagado por anticipado: es un activo. Son operaciones espejo y por
 * eso van al mismo catálogo con este distintivo.
 */
enum WithholdingScope: string
{
    case Purchase = 'purchase';
    case Sale = 'sale';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Retenemos al proveedor',
            self::Sale => 'Nos retiene el cliente',
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
