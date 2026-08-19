<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Enums;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Cost = 'cost';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Activo',
            self::Liability => 'Pasivo',
            self::Equity => 'Patrimonio',
            self::Income => 'Ingresos',
            self::Cost => 'Costos',
            self::Expense => 'Gastos',
        };
    }

    /**
     * Naturaleza del saldo: las cuentas de activo, costo y gasto aumentan por
     * el debe; las de pasivo, patrimonio e ingreso por el haber.
     */
    public function nature(): AccountNature
    {
        return match ($this) {
            self::Asset, self::Cost, self::Expense => AccountNature::Debit,
            self::Liability, self::Equity, self::Income => AccountNature::Credit,
        };
    }

    /**
     * Las cuentas de balance arrastran saldo entre ejercicios; las de resultado
     * se cancelan contra la utilidad en el cierre anual.
     */
    public function isBalanceSheet(): bool
    {
        return in_array($this, [self::Asset, self::Liability, self::Equity], strict: true);
    }

    public function isIncomeStatement(): bool
    {
        return ! $this->isBalanceSheet();
    }

    /**
     * Prefijo sugerido en el catálogo hondureño estándar.
     */
    public function defaultCodePrefix(): string
    {
        return match ($this) {
            self::Asset => '1',
            self::Liability => '2',
            self::Equity => '3',
            self::Income => '4',
            self::Cost => '5',
            self::Expense => '6',
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
