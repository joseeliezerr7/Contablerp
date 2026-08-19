<?php

declare(strict_types=1);

namespace App\Domains\Sales\Exceptions;

use App\Domains\Partners\Models\Customer;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Models\CreditNote;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SaleItem;
use App\Domains\Tenancy\Models\Branch;
use App\Support\Money;
use RuntimeException;

/**
 * Errores de negocio del módulo de ventas, redactados para el usuario.
 */
class SalesException extends RuntimeException
{
    public static function noLines(): self
    {
        return new self('La factura no tiene líneas.');
    }

    public static function notDraft(Sale $sale): self
    {
        return new self(sprintf(
            'La factura %s está %s y ya no puede modificarse.',
            $sale->number ?? '(borrador)',
            mb_strtolower($sale->status->label()),
        ));
    }

    public static function alreadyIssued(Sale $sale): self
    {
        return new self("La factura {$sale->number} ya fue emitida.");
    }

    public static function alreadyVoided(Sale $sale): self
    {
        return new self("La factura {$sale->number} ya está anulada.");
    }

    public static function notIssued(Sale $sale): self
    {
        return new self('Solo se pueden anular facturas emitidas.');
    }

    public static function inactiveCustomer(Customer $customer): self
    {
        return new self("El cliente {$customer->name} está inactivo.");
    }

    public static function creditLimitExceeded(Customer $customer, Money $balance, Money $total): self
    {
        return new self(sprintf(
            'La venta excede el límite de crédito de %s. Límite %s, saldo actual %s, esta factura %s. '
            .'Cámbiala a contado o pide autorización.',
            $customer->name,
            $customer->creditLimit()->format(),
            $balance->format(),
            $total->format(),
        ));
    }

    public static function noCreditTerms(Customer $customer): self
    {
        return new self(
            "El cliente {$customer->name} no tiene crédito autorizado; la venta debe ser de contado."
        );
    }

    public static function missingDepositAccount(): self
    {
        return new self(
            'Una venta de contado necesita la cuenta de caja o banco donde entra el dinero.'
        );
    }

    public static function missingWarehouse(): self
    {
        return new self(
            'La factura despacha mercadería con control de existencias, así que hay que indicar la bodega.'
        );
    }

    public static function hasPayments(Sale $sale): self
    {
        return new self(sprintf(
            'La factura %s tiene abonos aplicados. Anula primero los recibos de cobro.',
            $sale->number,
        ));
    }

    public static function emptyReason(): self
    {
        return new self('La anulación exige indicar un motivo.');
    }

    /*
    |--------------------------------------------------------------------------
    | Cobros de mostrador
    |--------------------------------------------------------------------------
    */

    public static function nonPositivePayment(): self
    {
        return new self('Un cobro tiene que ser por un importe positivo.');
    }

    public static function paymentNeedsReference(PaymentMethod $method): self
    {
        return new self(sprintf(
            'Un cobro por %s necesita su número de referencia: sin él, la conciliación bancaria '
            .'no puede casar el movimiento.',
            mb_strtolower($method->label()),
        ));
    }

    public static function paymentNeedsAccount(PaymentMethod $method): self
    {
        return new self(sprintf(
            'Falta indicar en qué cuenta entra el cobro por %s.',
            mb_strtolower($method->label()),
        ));
    }

    public static function noOpenCashSession(Branch $branch): self
    {
        return new self(sprintf(
            'No tenés una caja abierta en «%s». Sin sesión de caja el efectivo entraría sin que '
            .'ningún arqueo lo cuente, y el faltante aparecería mañana sin explicación.',
            $branch->name,
        ));
    }

    public static function noWalkInCustomer(): self
    {
        return new self(
            'No hay ningún cliente marcado como «de mostrador». Marcá uno en Clientes para que el '
            .'punto de venta sepa a quién facturarle cuando el cliente no se identifica.'
        );
    }

    public static function paymentsDoNotMatchTotal(Sale $sale, Money $paid): self
    {
        return new self(sprintf(
            'Los cobros suman L %s y la factura es de L %s. En una venta de contado tienen que '
            .'coincidir exactamente: lo que falte no quedaría registrado en ninguna parte.',
            $paid->format(),
            $sale->totalAmount()->format(),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Notas de crédito
    |--------------------------------------------------------------------------
    */

    public static function creditNoteNeedsIssuedSale(Sale $sale): self
    {
        return new self(sprintf(
            'Solo se acredita sobre una factura emitida, y la %s está %s. '
            .'Un borrador se corrige editándolo, y una factura anulada ya no le debe nada a nadie.',
            $sale->number ?? 'en borrador',
            mb_strtolower($sale->status->label()),
        ));
    }

    public static function creditNoteNotDraft(CreditNote $note): self
    {
        return new self(sprintf(
            'La nota de crédito %s ya no es un borrador y no se puede modificar.',
            $note->number ?? '(sin número)',
        ));
    }

    public static function creditNoteAlreadyIssued(CreditNote $note): self
    {
        return new self(sprintf('La nota de crédito %s ya fue emitida.', $note->number));
    }

    public static function creditNoteAlreadyVoided(CreditNote $note): self
    {
        return new self(sprintf('La nota de crédito %s ya está anulada.', $note->number));
    }

    public static function creditNoteNotIssued(CreditNote $note): self
    {
        return new self('Solo se anula una nota de crédito emitida.');
    }

    public static function creditExceedsSale(Sale $sale, Money $total): self
    {
        return new self(sprintf(
            'Las notas de crédito sumarían L %s sobre la factura %s, que es de L %s. '
            .'Acreditar de más le dejaría al cliente un saldo a favor que nadie autorizó.',
            $total->format(),
            $sale->number,
            $sale->totalAmount()->format(),
        ));
    }

    public static function creditQuantityExceedsSale(SaleItem $item, string $total): self
    {
        return new self(sprintf(
            'Se estarían devolviendo %s unidades de «%s» y la factura solo vendió %s.',
            rtrim(rtrim($total, '0'), '.'),
            $item->description,
            rtrim(rtrim((string) $item->quantity, '0'), '.'),
        ));
    }
}
