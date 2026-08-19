<?php

declare(strict_types=1);

namespace App\Domains\Payables\Exceptions;

use App\Domains\Payables\Models\Payable;
use App\Domains\Payables\Models\Payment;
use App\Support\Money;
use RuntimeException;

class PayableException extends RuntimeException
{
    public static function overApplied(Payable $payable, Money $amount): self
    {
        return new self(sprintf(
            'No se puede aplicar %s al documento %s: su saldo es %s.',
            $amount->format(),
            $payable->document_number,
            $payable->balanceAmount()->format(),
        ));
    }

    public static function voidedDocument(Payable $payable): self
    {
        return new self("El documento {$payable->document_number} está anulado.");
    }

    public static function cannotCancelWithPayments(Payable $payable): self
    {
        return new self(sprintf(
            'El documento %s tiene %s pagados; anula primero los pagos.',
            $payable->document_number,
            $payable->paidAmount()->format(),
        ));
    }

    public static function noApplications(): self
    {
        return new self('El pago debe aplicarse al menos a un documento.');
    }

    public static function paymentVoided(Payment $payment): self
    {
        return new self("El pago {$payment->number} ya está anulado.");
    }

    public static function foreignPayable(Payable $payable): self
    {
        return new self(sprintf(
            'El documento %s es de otro proveedor y no puede pagarse con este pago.',
            $payable->document_number,
        ));
    }

    public static function emptyReason(): self
    {
        return new self('La anulación exige indicar un motivo.');
    }
}
