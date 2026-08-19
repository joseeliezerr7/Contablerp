<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

/**
 * Origen de un movimiento de kardex.
 *
 * El signo de la cantidad ya dice si entra o sale; el tipo dice *por qué*, que
 * es lo que se necesita para leer un kardex y para explicar una diferencia.
 */
enum MovementType: string
{
    case Opening = 'opening';
    case Purchase = 'purchase';
    case Sale = 'sale';
    case PurchaseVoid = 'purchase_void';
    case SaleVoid = 'sale_void';
    case SaleReturn = 'sale_return';
    case SaleReturnVoid = 'sale_return_void';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Existencia inicial',
            self::Purchase => 'Compra',
            self::Sale => 'Venta',
            self::PurchaseVoid => 'Anulación de compra',
            self::SaleVoid => 'Anulación de venta',
            self::SaleReturn => 'Devolución de cliente',
            self::SaleReturnVoid => 'Anulación de nota de crédito',
            self::AdjustmentIn => 'Ajuste (entrada)',
            self::AdjustmentOut => 'Ajuste (salida)',
            self::TransferIn => 'Traslado recibido',
            self::TransferOut => 'Traslado enviado',
        };
    }

    /**
     * Si el movimiento suma existencias.
     *
     * Una entrada trae su propio costo y recalcula el promedio; una salida usa
     * el promedio vigente y no lo altera.
     */
    public function isInbound(): bool
    {
        return match ($this) {
            self::Opening, self::Purchase, self::SaleVoid, self::SaleReturn,
            self::AdjustmentIn, self::TransferIn => true,
            default => false,
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
