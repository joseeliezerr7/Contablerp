<?php

declare(strict_types=1);

namespace App\Domains\Accounting\DataTransfer;

use App\Domains\Accounting\Enums\AccountNature;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Support\Money;

/**
 * Una línea de cualquier estado financiero: la cuenta con su saldo inicial,
 * su movimiento del período y su saldo final.
 */
final readonly class StatementRow
{
    public function __construct(
        public int $accountId,
        public string $code,
        public string $name,
        public int $level,
        public AccountType $type,
        public AccountNature $nature,
        public Money $opening,
        public Money $debit,
        public Money $credit,
        public Money $closing,
    ) {}

    public static function make(Account $account, Money $opening, Money $debit, Money $credit): self
    {
        return new self(
            accountId: $account->id,
            code: $account->code,
            name: $account->name,
            level: $account->level,
            type: $account->type,
            nature: $account->nature,
            opening: $opening,
            debit: $debit,
            credit: $credit,
            // El saldo final respeta la naturaleza: en una cuenta acreedora el
            // abono suma. Usar siempre debe menos haber mostraría los pasivos
            // en negativo.
            closing: $opening->plus($account->nature->balanceOf($debit, $credit)),
        );
    }

    public function label(): string
    {
        return "{$this->code} — {$this->name}";
    }

    public function hasActivity(): bool
    {
        return ! $this->debit->isZero() || ! $this->credit->isZero() || ! $this->opening->isZero();
    }

    /**
     * Saldo deudor para el balance de comprobación: solo tiene valor cuando el
     * saldo cae del lado deudor.
     */
    public function debitBalance(): Money
    {
        return $this->signedBalance()->isPositive() ? $this->signedBalance() : Money::zero();
    }

    public function creditBalance(): Money
    {
        return $this->signedBalance()->isNegative() ? $this->signedBalance()->negated() : Money::zero();
    }

    /**
     * Saldo con el que la cuenta pesa dentro de su bloque del balance general.
     *
     * A diferencia de `closing`, que respeta la naturaleza **de la cuenta**,
     * este importe respeta la naturaleza **del tipo**: un activo aporta debe
     * menos haber, un pasivo o patrimonio aporta haber menos debe.
     *
     * La distinción existe por las cuentas de contrapartida —depreciación
     * acumulada, provisiones—, que son cuentas de activo con naturaleza
     * acreedora. Con `closing` su saldo sale positivo y **suma** al activo, que
     * es justo lo contrario de lo que hacen: descuentan. Sumándolas el balance
     * descuadra exactamente por el doble de su importe.
     */
    public function statementBalance(): Money
    {
        $natural = $this->type->nature();

        return $natural === $this->nature ? $this->closing : $this->closing->negated();
    }

    /**
     * Saldo con signo contable puro (debe menos haber), sin corregir por la
     * naturaleza. Es lo que necesita el balance de comprobación para decidir
     * en qué columna cae la cuenta.
     */
    private function signedBalance(): Money
    {
        $openingSigned = $this->nature === AccountNature::Debit
            ? $this->opening
            : $this->opening->negated();

        return $openingSigned->plus($this->debit)->minus($this->credit);
    }
}
