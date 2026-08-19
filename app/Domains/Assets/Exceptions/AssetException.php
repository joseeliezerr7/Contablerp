<?php

declare(strict_types=1);

namespace App\Domains\Assets\Exceptions;

use App\Domains\Assets\Models\DepreciationRun;
use App\Domains\Assets\Models\FixedAsset;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use RuntimeException;

class AssetException extends RuntimeException
{
    public static function periodAlreadyRun(DateTimeInterface|string $month): self
    {
        return new self(sprintf(
            'La depreciación de %s ya se ejecutó. Anula esa corrida si necesitas rehacerla.',
            CarbonImmutable::parse($month)->translatedFormat('F \d\e Y'),
        ));
    }

    public static function nothingToDepreciate(DateTimeInterface|string $month): self
    {
        return new self(sprintf(
            'No hay activos que depreciar en %s.',
            CarbonImmutable::parse($month)->translatedFormat('F \d\e Y'),
        ));
    }

    public static function runVoided(DepreciationRun $run): self
    {
        return new self('La '.lcfirst($run->label()).' ya está anulada.');
    }

    public static function laterRunExists(DepreciationRun $run): self
    {
        return new self(sprintf(
            'No se puede anular la %s porque hay corridas posteriores. Anúlalas primero, de la más reciente a la más antigua.',
            lcfirst($run->label()),
        ));
    }

    public static function assetDisposed(FixedAsset $asset): self
    {
        return new self($asset->label().' ya está dado de baja.');
    }

    public static function invalidCost(): self
    {
        return new self('El costo del activo debe ser mayor que cero.');
    }

    public static function salvageAboveCost(): self
    {
        return new self('El valor residual no puede ser mayor que el costo.');
    }

    public static function invalidUsefulLife(): self
    {
        return new self('La vida útil debe ser de al menos un mes.');
    }

    public static function disposalBeforeAcquisition(FixedAsset $asset): self
    {
        return new self(sprintf(
            'No se puede dar de baja %s antes de la fecha en que se adquirió (%s).',
            $asset->label(),
            $asset->acquired_on->format('d/m/Y'),
        ));
    }
}
