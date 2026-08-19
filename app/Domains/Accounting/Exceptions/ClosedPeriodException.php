<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Exceptions;

use App\Domains\Accounting\Models\AccountingPeriod;
use DateTimeInterface;

final class ClosedPeriodException extends AccountingException
{
    public static function forPeriod(AccountingPeriod $period): self
    {
        return new self(sprintf(
            'El período %s está %s y no admite movimientos. Registra la operación en un período abierto o pide su reapertura.',
            $period->name,
            mb_strtolower($period->status->label()),
        ));
    }

    public static function noPeriodFor(DateTimeInterface $date): self
    {
        return new self(sprintf(
            'No existe un período contable que contenga la fecha %s. Crea el ejercicio fiscal correspondiente.',
            $date->format('d/m/Y'),
        ));
    }
}
