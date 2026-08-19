<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exceptions;

use App\Domains\Tenancy\Models\Company;
use App\Models\User;
use RuntimeException;

/**
 * Errores de la gestión de usuarios, redactados para quien administra.
 *
 * La razón social nunca va justo antes de un punto: en Honduras casi todas
 * terminan en abreviatura —«S. de R.L.», «S.A. de C.V.»— y el mensaje salía con
 * dos puntos seguidos. Va seguida de coma, de dos puntos, o en medio de la frase.
 */
class IdentityException extends RuntimeException
{
    public static function emailBelongsToAnotherTenant(string $email): self
    {
        return new self(sprintf(
            'El correo %s ya está registrado en otra cuenta del sistema. '
            .'Una persona no puede pertenecer a dos cuentas distintas con el mismo correo.',
            $email,
        ));
    }

    public static function alreadyHasAccess(User $user, Company $company): self
    {
        return new self(sprintf(
            '%s ya tiene acceso a %s, así que editá su rol en la lista en vez de invitarlo de nuevo.',
            $user->name,
            $company->legal_name,
        ));
    }

    public static function notInCompany(User $user, Company $company): self
    {
        return new self(sprintf(
            'En %s no hay ningún acceso a nombre de %s.',
            $company->legal_name,
            $user->name,
        ));
    }

    public static function cannotActOnSelf(string $action): self
    {
        return new self(sprintf(
            'No podés %s. Pedíselo a otro administrador.',
            $action,
        ));
    }

    public static function lastAdministrator(Company $company): self
    {
        return new self(sprintf(
            'En %s es el único administrador activo. Nombrá a otro antes de quitarle el rol: '
            .'sin administrador, nadie dentro de la empresa puede volver a asignarlo.',
            $company->legal_name,
        ));
    }
}
