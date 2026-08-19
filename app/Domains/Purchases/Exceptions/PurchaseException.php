<?php

declare(strict_types=1);

namespace App\Domains\Purchases\Exceptions;

use App\Domains\Partners\Models\Supplier;
use App\Domains\Purchases\Models\Purchase;
use RuntimeException;

class PurchaseException extends RuntimeException
{
    public static function noLines(): self
    {
        return new self('La compra no tiene líneas.');
    }

    public static function notDraft(Purchase $purchase): self
    {
        return new self(sprintf(
            'La compra %s está %s y ya no puede modificarse.',
            $purchase->number ?? '(borrador)',
            mb_strtolower($purchase->status->label()),
        ));
    }

    public static function alreadyReceived(Purchase $purchase): self
    {
        return new self("La compra {$purchase->number} ya fue recibida.");
    }

    public static function alreadyVoided(Purchase $purchase): self
    {
        return new self("La compra {$purchase->number} ya está anulada.");
    }

    public static function notReceived(): self
    {
        return new self('Solo se pueden anular compras recibidas.');
    }

    public static function inactiveSupplier(Supplier $supplier): self
    {
        return new self("El proveedor {$supplier->name} está inactivo.");
    }

    public static function duplicateInvoice(Supplier $supplier, string $invoiceNumber): self
    {
        return new self(sprintf(
            'La factura %s del proveedor %s ya está registrada. Registrarla dos veces duplicaría '
            .'el gasto y el crédito fiscal.',
            $invoiceNumber,
            $supplier->name,
        ));
    }

    public static function missingInvoiceNumber(): self
    {
        return new self('Indica el número de la factura del proveedor.');
    }

    public static function missingPaymentAccount(): self
    {
        return new self('Una compra de contado necesita la cuenta de caja o banco de la que sale el dinero.');
    }

    public static function missingWarehouse(): self
    {
        return new self('La compra ingresa mercadería a existencias, así que hay que indicar la bodega.');
    }

    public static function hasPayments(Purchase $purchase): self
    {
        return new self(sprintf(
            'La compra %s tiene pagos aplicados. Anula primero los pagos al proveedor.',
            $purchase->number,
        ));
    }

    public static function emptyReason(): self
    {
        return new self('La anulación exige indicar un motivo.');
    }
}
