<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Enums;

use App\Support\Money;

enum AccountNature: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Deudora',
            self::Credit => 'Acreedora',
        };
    }

    /**
     * Saldo con signo según la naturaleza de la cuenta: una cuenta deudora con
     * más haber que debe tiene saldo negativo, y viceversa.
     */
    public function balanceOf(Money $debit, Money $credit): Money
    {
        return match ($this) {
            self::Debit => $debit->minus($credit),
            self::Credit => $credit->minus($debit),
        };
    }

    public function opposite(): self
    {
        return match ($this) {
            self::Debit => self::Credit,
            self::Credit => self::Debit,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
