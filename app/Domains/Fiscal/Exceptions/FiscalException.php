<?php

declare(strict_types=1);

namespace App\Domains\Fiscal\Exceptions;

use App\Domains\Fiscal\Enums\FiscalDocumentType;
use App\Domains\Fiscal\Models\FiscalAuthorization;
use App\Domains\Fiscal\Models\FiscalPoint;
use App\Domains\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Fallos del régimen de facturación.
 *
 * Los mensajes son largos a propósito. Quien se topa con ellos está intentando
 * facturar y tiene un cliente delante: decirle «autorización no válida» lo deja
 * igual de atascado. Cada mensaje dice qué pasó y qué hacer.
 */
class FiscalException extends DomainException
{
    public static function noPointForBranch(Branch $branch): self
    {
        return new self(sprintf(
            'La sucursal «%s» no tiene punto de emisión configurado. '
            .'Registrá el establecimiento y el punto que te asignó el SAR antes de facturar.',
            $branch->name,
        ));
    }

    public static function noAuthorization(FiscalPoint $point, FiscalDocumentType $type): self
    {
        return new self(sprintf(
            'El punto de emisión %s no tiene una autorización vigente para %s. '
            .'Cargá el CAI que te entregó el SAR con su rango y su fecha límite.',
            $point->prefix(),
            mb_strtolower($type->label()),
        ));
    }

    public static function rangeExhausted(FiscalAuthorization $authorization): self
    {
        return new self(sprintf(
            'Se agotó el rango autorizado del CAI %s: el último correlativo era el %s. '
            .'Hay que solicitarle al SAR una autorización nueva; el sistema no puede seguir numerando.',
            $authorization->cai,
            $authorization->formatNumber($authorization->range_to),
        ));
    }

    public static function expired(FiscalAuthorization $authorization, string $date): self
    {
        return new self(sprintf(
            'El CAI %s venció el %s y la factura se está emitiendo con fecha %s. '
            .'Un documento emitido después de la fecha límite no es válido: solicitá una autorización nueva.',
            $authorization->cai,
            CarbonImmutable::parse($authorization->limit_date)->format('d/m/Y'),
            CarbonImmutable::parse($date)->format('d/m/Y'),
        ));
    }

    public static function notActive(FiscalAuthorization $authorization): self
    {
        return new self(sprintf(
            'La autorización %s está %s y no puede emitir documentos.',
            $authorization->cai,
            mb_strtolower($authorization->status->label()),
        ));
    }

    public static function invalidRange(int $from, int $to): self
    {
        return new self(sprintf(
            'El rango autorizado está al revés: el correlativo inicial (%d) no puede ser mayor que el final (%d).',
            $from,
            $to,
        ));
    }

    public static function rangeOverlaps(FiscalAuthorization $existing): self
    {
        return new self(sprintf(
            'El rango se cruza con el de la autorización %s (%s). '
            .'Dos autorizaciones que comparten correlativos producirían dos documentos con el mismo número.',
            $existing->cai,
            $existing->rangeLabel(),
        ));
    }

    public static function alreadyUsed(FiscalAuthorization $authorization): self
    {
        return new self(sprintf(
            'La autorización %s ya emitió %d documento(s) y no se puede modificar. '
            .'Cambiarle el CAI o el rango contradiría los papeles que ya están en manos de los clientes.',
            $authorization->cai,
            $authorization->used(),
        ));
    }
}
