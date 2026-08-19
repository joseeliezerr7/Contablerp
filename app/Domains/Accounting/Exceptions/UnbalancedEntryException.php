<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Exceptions;

use App\Support\Money;

/**
 * La regla que sostiene todo el sistema: total debe = total haber.
 */
final class UnbalancedEntryException extends AccountingException
{
    public static function make(Money $debit, Money $credit): self
    {
        $difference = $debit->minus($credit);

        return new self(sprintf(
            'La partida está descuadrada: debe %s, haber %s, diferencia %s.',
            $debit->format(),
            $credit->format(),
            $difference->format(),
        ));
    }

    public static function empty(): self
    {
        return new self('La partida no tiene importes: el total de debe y haber es cero.');
    }

    public static function tooFewLines(int $count): self
    {
        return new self("Una partida necesita al menos dos líneas; se recibieron {$count}.");
    }
}
