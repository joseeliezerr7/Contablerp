<?php

declare(strict_types=1);

namespace App\Domains\Accounting\DataTransfer;

use App\Domains\Accounting\Enums\JournalEntryType;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Partida propuesta. Es lo único que los módulos operativos (ventas, compras,
 * bancos, activos fijos) entregan al motor contable: nadie escribe en
 * `journal_entries` directamente.
 *
 * Constructor fluido para que el asiento se lea en el código igual que en el
 * papel:
 *
 *     JournalDraft::on($fecha, 'Factura FAC-001 — Cliente XYZ')
 *         ->debit($clientes, '11500.00')
 *         ->credit($ventas, '10000.00')
 *         ->credit($isvPorPagar, '1500.00')
 *         ->fromDocument('sale', $venta->id);
 */
final class JournalDraft
{
    /** @var array<int, JournalLineDraft> */
    private array $lines = [];

    private JournalEntryType $type = JournalEntryType::Standard;

    private ?int $branchId = null;

    private ?string $reference = null;

    private ?string $sourceType = null;

    private ?int $sourceId = null;

    private string $currency = 'HNL';

    private string $exchangeRate = '1';

    private function __construct(
        private readonly CarbonImmutable $date,
        private readonly string $concept,
    ) {}

    public static function on(DateTimeInterface|string $date, string $concept): self
    {
        return new self(CarbonImmutable::parse($date)->startOfDay(), $concept);
    }

    public function debit(int $accountId, Money|int|string $amount, ?string $description = null, ?int $branchId = null): self
    {
        $this->lines[] = JournalLineDraft::debit($accountId, $amount, $description, $branchId ?? $this->branchId);

        return $this;
    }

    public function credit(int $accountId, Money|int|string $amount, ?string $description = null, ?int $branchId = null): self
    {
        $this->lines[] = JournalLineDraft::credit($accountId, $amount, $description, $branchId ?? $this->branchId);

        return $this;
    }

    public function addLine(JournalLineDraft $line): self
    {
        $this->lines[] = $line;

        return $this;
    }

    /**
     * @param  array<int, JournalLineDraft>  $lines
     */
    public function addLines(array $lines): self
    {
        foreach ($lines as $line) {
            $this->addLine($line);
        }

        return $this;
    }

    public function ofType(JournalEntryType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function inBranch(?int $branchId): self
    {
        $this->branchId = $branchId;

        return $this;
    }

    public function withReference(?string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    /**
     * Vincula la partida al documento que la origina. Es lo que permite al
     * motor rechazar una segunda contabilización del mismo documento.
     */
    public function fromDocument(string $type, int $id): self
    {
        $this->sourceType = $type;
        $this->sourceId = $id;

        return $this;
    }

    public function inCurrency(string $currency, Money|string $exchangeRate = '1'): self
    {
        $this->currency = $currency;
        $this->exchangeRate = (string) $exchangeRate;

        return $this;
    }

    public function date(): CarbonImmutable
    {
        return $this->date;
    }

    public function concept(): string
    {
        return $this->concept;
    }

    /** @return array<int, JournalLineDraft> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function type(): JournalEntryType
    {
        return $this->type;
    }

    public function branchId(): ?int
    {
        return $this->branchId;
    }

    public function reference(): ?string
    {
        return $this->reference;
    }

    public function sourceType(): ?string
    {
        return $this->sourceType;
    }

    public function sourceId(): ?int
    {
        return $this->sourceId;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function exchangeRate(): string
    {
        return $this->exchangeRate;
    }

    public function totalDebit(): Money
    {
        return Money::sum(array_map(fn (JournalLineDraft $line) => $line->debit, $this->lines));
    }

    public function totalCredit(): Money
    {
        return Money::sum(array_map(fn (JournalLineDraft $line) => $line->credit, $this->lines));
    }

    public function difference(): Money
    {
        return $this->totalDebit()->minus($this->totalCredit());
    }

    public function isBalanced(): bool
    {
        return $this->difference()->isZero();
    }

    /**
     * @return array<int, int>
     */
    public function accountIds(): array
    {
        return array_values(array_unique(array_map(
            fn (JournalLineDraft $line) => $line->accountId,
            $this->lines,
        )));
    }
}
