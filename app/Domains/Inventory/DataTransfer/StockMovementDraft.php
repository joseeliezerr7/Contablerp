<?php

declare(strict_types=1);

namespace App\Domains\Inventory\DataTransfer;

use App\Domains\Inventory\Enums\MovementType;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Un movimiento de kardex antes de aplicarse, con el mismo espíritu que
 * `JournalDraft`: se arma, se valida y el servicio decide si entra.
 *
 * La diferencia entre entrada y salida no es solo el signo. **Una entrada trae
 * su valor; una salida lo averigua.** Al recibir mercadería el valor lo dicta
 * la factura del proveedor, y es exactamente el importe que se asentó en la
 * cuenta de inventario. Al despacharla, el valor sale del promedio que hay en
 * ese momento, y nadie puede imponerlo desde fuera —si se pudiera, el kardex y
 * la contabilidad dejarían de ser el mismo número—.
 *
 * Por eso `in()` exige `Money` y `out()` no lo acepta.
 */
final readonly class StockMovementDraft
{
    /**
     * @param  string  $quantity  Siempre positiva; el signo lo pone el tipo.
     * @param  Money|null  $value  Solo en entradas: el importe que entra al inventario.
     */
    private function __construct(
        public int $productId,
        public int $warehouseId,
        public MovementType $type,
        public string $quantity,
        public CarbonImmutable $date,
        public ?Money $value = null,
        public ?string $sourceType = null,
        public ?int $sourceId = null,
        public ?string $reference = null,
        public ?string $description = null,
    ) {}

    /**
     * Entrada de mercadería, con el valor que se asienta en la contabilidad.
     *
     * Recibe el **valor total** y no el costo unitario a propósito: multiplicar
     * un costo unitario por la cantidad puede dar un centavo distinto al que se
     * contabilizó, y ese centavo no estaría en ninguna cuenta.
     */
    public static function in(
        int $productId,
        int $warehouseId,
        string $quantity,
        Money $value,
        MovementType $type,
        DateTimeInterface|string $date,
    ): self {
        return new self(
            $productId, $warehouseId, $type, $quantity,
            CarbonImmutable::parse($date)->startOfDay(), $value,
        );
    }

    /**
     * Salida de mercadería. El valor lo calcula el servicio con el promedio.
     */
    public static function out(
        int $productId,
        int $warehouseId,
        string $quantity,
        MovementType $type,
        DateTimeInterface|string $date,
    ): self {
        return new self(
            $productId, $warehouseId, $type, $quantity,
            CarbonImmutable::parse($date)->startOfDay(),
        );
    }

    public function fromDocument(string $type, int $id, ?string $reference = null): self
    {
        return $this->with(sourceType: $type, sourceId: $id, reference: $reference ?? $this->reference);
    }

    public function describedAs(?string $description): self
    {
        return $this->with(description: $description);
    }

    /**
     * Fuerza el valor de una entrada cuyo importe ya se conoce. Se usa al
     * devolver mercadería por una anulación: el valor que vuelve tiene que ser
     * el mismo que salió, no el promedio de hoy.
     */
    public function valuedAt(Money $value): self
    {
        return $this->with(value: $value);
    }

    public function isInbound(): bool
    {
        return $this->type->isInbound();
    }

    /**
     * Cantidad con signo, como se guarda en el kardex.
     */
    public function signedQuantity(): string
    {
        return $this->isInbound() ? $this->quantity : '-'.ltrim($this->quantity, '-');
    }

    private function with(
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $reference = null,
        ?string $description = null,
        ?Money $value = null,
    ): self {
        return new self(
            $this->productId,
            $this->warehouseId,
            $this->type,
            $this->quantity,
            $this->date,
            $value ?? $this->value,
            $sourceType ?? $this->sourceType,
            $sourceId ?? $this->sourceId,
            $reference ?? $this->reference,
            $description ?? $this->description,
        );
    }
}
