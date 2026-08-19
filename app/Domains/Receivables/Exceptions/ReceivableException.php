<?php

declare(strict_types=1);

namespace App\Domains\Receivables\Exceptions;

use App\Domains\Receivables\Models\Receipt;
use App\Domains\Receivables\Models\Receivable;
use App\Support\Money;
use RuntimeException;

class ReceivableException extends RuntimeException
{
    public static function overApplied(Receivable $receivable, Money $amount): self
    {
        return new self(sprintf(
            'No se puede aplicar %s al documento %s: su saldo es %s.',
            $amount->format(),
            $receivable->document_number,
            $receivable->balanceAmount()->format(),
        ));
    }

    public static function alreadyPaid(Receivable $receivable): self
    {
        return new self("El documento {$receivable->document_number} ya está cancelado.");
    }

    public static function voidedDocument(Receivable $receivable): self
    {
        return new self("El documento {$receivable->document_number} está anulado.");
    }

    public static function cannotCancelWithPayments(Receivable $receivable): self
    {
        return new self(sprintf(
            'El documento %s tiene %s cobrados; anula primero los recibos.',
            $receivable->document_number,
            $receivable->paidAmount()->format(),
        ));
    }

    public static function noApplications(): self
    {
        return new self('El recibo debe aplicarse al menos a un documento.');
    }

    public static function amountMismatch(Money $receipt, Money $applied): self
    {
        return new self(sprintf(
            'El importe del recibo (%s) no coincide con lo aplicado a los documentos (%s).',
            $receipt->format(),
            $applied->format(),
        ));
    }

    public static function receiptVoided(Receipt $receipt): self
    {
        return new self("El recibo {$receipt->number} ya está anulado.");
    }

    public static function foreignReceivable(Receivable $receivable): self
    {
        return new self(sprintf(
            'El documento %s es de otro cliente y no puede cobrarse con este recibo.',
            $receivable->document_number,
        ));
    }

    public static function emptyReason(): self
    {
        return new self('La anulación exige indicar un motivo.');
    }
}
