<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Exceptions;

use App\Domains\Accounting\Models\Account;

final class InvalidAccountException extends AccountingException
{
    public static function notPostable(Account $account): self
    {
        return new self(sprintf(
            'La cuenta %s es de agrupación y no admite movimientos. Usa una de sus cuentas de detalle.',
            $account->label(),
        ));
    }

    public static function inactive(Account $account): self
    {
        return new self("La cuenta {$account->label()} está inactiva.");
    }

    public static function notFound(int $accountId): self
    {
        return new self("La cuenta {$accountId} no existe en esta empresa.");
    }

    public static function requiresPartner(Account $account): self
    {
        return new self(
            "La cuenta {$account->label()} exige indicar el cliente o proveedor en la línea."
        );
    }

    public static function requiresBranch(Account $account): self
    {
        return new self("La cuenta {$account->label()} exige indicar la sucursal en la línea.");
    }

    public static function parentHasMovements(Account $account): self
    {
        return new self(sprintf(
            'La cuenta %s ya tiene movimientos, así que no puede convertirse en cuenta de agrupación. '
            .'Crea la subcuenta bajo otra cuenta o reclasifica primero los movimientos.',
            $account->label(),
        ));
    }

    public static function codeNotUnderParent(string $code, Account $parent): self
    {
        return new self(sprintf(
            'El código %s no cuelga de %s. Una subcuenta debe empezar con el código de su cuenta padre.',
            $code,
            $parent->code,
        ));
    }

    public static function hasMovements(Account $account): self
    {
        return new self(
            "La cuenta {$account->label()} tiene movimientos registrados y no puede eliminarse ni cambiar de tipo."
        );
    }

    public static function hasChildren(Account $account): self
    {
        return new self("La cuenta {$account->label()} tiene subcuentas; elimínalas primero.");
    }

    public static function isSystem(Account $account): self
    {
        return new self(sprintf(
            'La cuenta %s la necesita el motor contable y no puede eliminarse. Puedes desactivarla.',
            $account->label(),
        ));
    }

    public static function missingMapping(string $key): self
    {
        return new self(sprintf(
            'No hay una cuenta configurada para «%s». Asígnala en Configuración → Cuentas por módulo.',
            $key,
        ));
    }
}
