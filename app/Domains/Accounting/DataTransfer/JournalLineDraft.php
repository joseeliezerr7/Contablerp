<?php

declare(strict_types=1);

namespace App\Domains\Accounting\DataTransfer;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Línea propuesta de una partida, antes de tocar la base de datos.
 *
 * Es cargo o abono, nunca ambos: se construye con los constructores nombrados
 * `debit()` y `credit()`, así que una línea con los dos lados llenos ni
 * siquiera se puede expresar.
 */
final readonly class JournalLineDraft
{
    private function __construct(
        public int $accountId,
        public Money $debit,
        public Money $credit,
        public ?string $description = null,
        public ?int $branchId = null,
        public ?string $partnerType = null,
        public ?int $partnerId = null,
        public ?string $documentRef = null,
    ) {}

    public static function debit(
        int $accountId,
        Money|int|string $amount,
        ?string $description = null,
        ?int $branchId = null,
        ?string $partnerType = null,
        ?int $partnerId = null,
        ?string $documentRef = null,
    ): self {
        return new self(
            accountId: $accountId,
            debit: self::positiveAmount($amount),
            credit: Money::zero(),
            description: $description,
            branchId: $branchId,
            partnerType: $partnerType,
            partnerId: $partnerId,
            documentRef: $documentRef,
        );
    }

    public static function credit(
        int $accountId,
        Money|int|string $amount,
        ?string $description = null,
        ?int $branchId = null,
        ?string $partnerType = null,
        ?int $partnerId = null,
        ?string $documentRef = null,
    ): self {
        return new self(
            accountId: $accountId,
            debit: Money::zero(),
            credit: self::positiveAmount($amount),
            description: $description,
            branchId: $branchId,
            partnerType: $partnerType,
            partnerId: $partnerId,
            documentRef: $documentRef,
        );
    }

    public function isDebit(): bool
    {
        return $this->debit->isPositive();
    }

    public function amount(): Money
    {
        return $this->isDebit() ? $this->debit : $this->credit;
    }

    /**
     * Misma línea con el importe al lado contrario. Base de las reversiones.
     */
    public function inverted(): self
    {
        return $this->isDebit()
            ? self::credit($this->accountId, $this->debit, $this->description, $this->branchId, $this->partnerType, $this->partnerId, $this->documentRef)
            : self::debit($this->accountId, $this->credit, $this->description, $this->branchId, $this->partnerType, $this->partnerId, $this->documentRef);
    }

    private static function positiveAmount(Money|int|string $amount): Money
    {
        $money = $amount instanceof Money ? $amount : Money::of($amount);

        if (! $money->isPositive()) {
            throw new InvalidArgumentException(
                'El importe de una línea debe ser mayor que cero. Para invertir el signo, '
                .'usa el lado contrario de la partida.'
            );
        }

        return $money;
    }
}
