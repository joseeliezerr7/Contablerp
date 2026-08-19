<?php

declare(strict_types=1);

namespace App\Domains\Billing\Exceptions;

use App\Domains\Billing\Models\Subscription;
use RuntimeException;

class BillingException extends RuntimeException
{
    public static function quotaReached(string $resource, int $limit): self
    {
        return new self(sprintf(
            'El plan contratado permite hasta %d %s. Cambia de plan para añadir más.',
            $limit,
            $resource,
        ));
    }

    public static function alreadySubscribed(): self
    {
        return new self('Esta cuenta ya tiene una suscripción activa.');
    }

    public static function noSubscription(): self
    {
        return new self('Esta cuenta no tiene ninguna suscripción.');
    }

    public static function alreadyCancelled(Subscription $subscription): self
    {
        return new self('La suscripción ya está cancelada.');
    }

    public static function notLive(Subscription $subscription): self
    {
        return new self('La suscripción está cancelada y no admite cambios.');
    }

    public static function emailTaken(string $email): self
    {
        return new self("Ya existe una cuenta con el correo {$email}.");
    }

    public static function planUnavailable(): self
    {
        return new self('Ese plan no está disponible.');
    }
}
