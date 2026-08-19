<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Exceptions;

use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Models\StockAdjustment;
use App\Domains\Inventory\Models\StockTransfer;
use RuntimeException;

class InventoryException extends RuntimeException
{
    public static function notTracked(Product $product): self
    {
        return new self(sprintf(
            'El producto %s no lleva control de existencias, así que no puede moverse en el kardex.',
            $product->label(),
        ));
    }

    public static function nonPositiveQuantity(): self
    {
        return new self('La cantidad de un movimiento de inventario debe ser mayor que cero.');
    }

    public static function outsideTransaction(): self
    {
        return new self(
            'Un movimiento de inventario debe registrarse dentro de una transacción, '
            .'junto con el documento que lo origina.'
        );
    }

    public static function sameWarehouse(): self
    {
        return new self('La bodega de origen y la de destino no pueden ser la misma.');
    }

    public static function warehouseRequired(): self
    {
        return new self(
            'El documento mueve existencias, así que debe indicar la bodega.'
        );
    }

    public static function emptyDocument(): self
    {
        return new self('El documento no tiene líneas.');
    }

    public static function emptyReason(): self
    {
        return new self('Hay que indicar el motivo.');
    }

    public static function adjustmentNotDraft(StockAdjustment $adjustment): self
    {
        return new self(sprintf(
            'El ajuste %s ya no está en borrador.',
            $adjustment->label(),
        ));
    }

    public static function adjustmentNotPosted(StockAdjustment $adjustment): self
    {
        return new self(sprintf(
            'El ajuste %s no está aplicado, así que no hay nada que anular.',
            $adjustment->label(),
        ));
    }

    public static function transferNotDraft(StockTransfer $transfer): self
    {
        return new self(sprintf(
            'El traslado %s ya no está en borrador.',
            $transfer->label(),
        ));
    }

    public static function transferNotPosted(StockTransfer $transfer): self
    {
        return new self(sprintf(
            'El traslado %s no está aplicado, así que no hay nada que anular.',
            $transfer->label(),
        ));
    }
}
