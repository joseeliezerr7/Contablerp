<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Exceptions;

use App\Domains\Catalog\Models\Product;
use App\Domains\Tenancy\Models\Warehouse;

/**
 * Se lanza dentro de la transacción del documento, de modo que la factura
 * entera se revierte. El kardex nunca queda en negativo y el costo promedio
 * siempre es un número válido.
 */
class InsufficientStockException extends InventoryException
{
    public static function for(Product $product, Warehouse $warehouse, string $requested, string $available): self
    {
        return new self(sprintf(
            'No hay existencia suficiente de %s en %s: se piden %s y hay %s.',
            $product->label(),
            $warehouse->label(),
            rtrim(rtrim($requested, '0'), '.'),
            rtrim(rtrim($available, '0'), '.'),
        ));
    }
}
