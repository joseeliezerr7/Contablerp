<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Services;

use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\DataTransfer\StockMovementDraft;
use App\Domains\Inventory\Exceptions\InsufficientStockException;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Inventory\Models\InventoryMovement;
use App\Domains\Inventory\Models\InventoryStock;
use App\Domains\Tenancy\Models\Warehouse;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Único escritor del kardex, igual que `AccountingEngine` lo es del libro
 * diario. Ningún otro punto del sistema inserta en `inventory_movements` ni
 * toca `inventory_stocks`: si lo hiciera, existiría un camino por el que las
 * existencias cambian sin dejar rastro, que es exactamente lo que un kardex
 * está para impedir.
 *
 * ## Costeo promedio ponderado móvil
 *
 * Cada entrada recalcula el promedio; cada salida lo usa sin alterarlo:
 *
 *     promedio = valor total en existencia / cantidad en existencia
 *
 * Lo que se guarda es el par **(cantidad, valor)**. El promedio se deriva, y
 * eso no es un detalle de implementación: es lo que garantiza que la suma del
 * kardex valorizado sea exactamente el saldo de la cuenta contable de
 * inventario. Si el promedio se guardara redondeado y el valor se recalculara
 * multiplicando, cada operación dejaría un resto de centavos que no está en
 * ninguna cuenta, y a los pocos meses los dos números dejarían de coincidir.
 *
 * ## El valor de una salida
 *
 * No se calcula como `promedio × cantidad`, sino repartiendo el valor en
 * existencia en proporción a lo que sale:
 *
 *     valor que sale = valor en existencia × cantidad que sale / cantidad en existencia
 *
 * La diferencia importa en el último despacho. Con 3 unidades que costaron
 * 100.00, el promedio es 33.333333; sacar las 3 de una por `promedio × 1` daría
 * 33.33 tres veces —99.99— y dejaría un centavo de inventario sin unidades que
 * lo respalden. Por eso, además, **la salida que vacía la existencia se lleva
 * todo el valor restante**: el saldo queda en cero exacto y no en 0.0001.
 */
final class InventoryService
{
    /**
     * Escala de las cantidades. Hay unidades que se fraccionan —kilos, metros,
     * litros—, y 6 decimales es lo que declaran las columnas.
     */
    private const QUANTITY_SCALE = 6;

    public function __construct(private readonly CompanyContext $context) {}

    /**
     * Aplica un movimiento: escribe el kardex y actualiza la existencia.
     *
     * Debe llamarse dentro de la transacción del documento que lo origina, para
     * que la mercadería y su factura entren o no entren juntas.
     */
    public function apply(StockMovementDraft $draft): InventoryMovement
    {
        if (DB::transactionLevel() === 0) {
            throw InventoryException::outsideTransaction();
        }

        if (bccomp($draft->quantity, '0', self::QUANTITY_SCALE) <= 0) {
            throw InventoryException::nonPositiveQuantity();
        }

        $product = Product::query()->findOrFail($draft->productId);

        if ($product->track_inventory !== true) {
            throw InventoryException::notTracked($product);
        }

        $stock = $this->lockStock($draft->warehouseId, $draft->productId);

        [$quantityChange, $valueChange] = $draft->isInbound()
            ? $this->inbound($draft)
            : $this->outbound($draft, $stock, $product);

        $newQuantity = bcadd($stock->quantity, $quantityChange, self::QUANTITY_SCALE);
        $newValue = Money::of($stock->total_value)->plus($valueChange);

        $stock->forceFill([
            'quantity' => $newQuantity,
            'total_value' => $newValue->toString(),
        ])->save();

        return $this->writeMovement($draft, $quantityChange, $valueChange, $newQuantity, $newValue);
    }

    /**
     * @param  array<int, StockMovementDraft>  $drafts
     * @return array<int, InventoryMovement>
     */
    public function applyMany(array $drafts): array
    {
        return array_map(fn (StockMovementDraft $draft) => $this->apply($draft), $drafts);
    }

    /**
     * Existencia actual, o null si el producto nunca ha entrado a esa bodega.
     */
    public function stockFor(int $productId, int $warehouseId): ?InventoryStock
    {
        return InventoryStock::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();
    }

    public function availableQuantity(int $productId, int $warehouseId): string
    {
        return $this->stockFor($productId, $warehouseId)?->quantity ?? '0.000000';
    }

    public function averageCost(int $productId, int $warehouseId): Money
    {
        $stock = $this->stockFor($productId, $warehouseId);

        return $stock === null ? Money::zero() : Money::of($stock->average_cost);
    }

    /**
     * Valor total del inventario de la empresa, para contrastarlo con el saldo
     * de la cuenta contable.
     */
    public function totalValue(): Money
    {
        $sum = InventoryStock::query()->sum('total_value');

        return Money::of((string) $sum);
    }

    /*
    |--------------------------------------------------------------------------
    | Interno
    |--------------------------------------------------------------------------
    */

    /**
     * Entrada: el valor viene dado por el documento.
     *
     * @return array{0: string, 1: Money}
     */
    private function inbound(StockMovementDraft $draft): array
    {
        return [$draft->quantity, $draft->value ?? Money::zero()];
    }

    /**
     * Salida: el valor se reparte en proporción a lo que sale, y la salida que
     * vacía la bodega se lleva el resto exacto.
     *
     * @return array{0: string, 1: Money}
     */
    private function outbound(StockMovementDraft $draft, InventoryStock $stock, Product $product): array
    {
        $available = $stock->quantity;

        if (bccomp($available, $draft->quantity, self::QUANTITY_SCALE) < 0) {
            throw InsufficientStockException::for(
                $product,
                Warehouse::query()->findOrFail($draft->warehouseId),
                $draft->quantity,
                $available,
            );
        }

        // Salida con valor impuesto: solo la usa la anulación de una compra,
        // que tiene que sacar exactamente el valor que aquella metió. Si en su
        // lugar saliera al promedio de hoy, el kardex y la reversión contable
        // —que revierte los importes originales— dejarían de coincidir.
        if ($draft->value !== null) {
            return ['-'.$draft->quantity, $draft->value->negated()];
        }

        $inStock = Money::of($stock->total_value);

        // Se lleva todo: el saldo queda en cero exacto, sin restos de centavos
        // que quedarían sin unidades que los respalden.
        if (bccomp($available, $draft->quantity, self::QUANTITY_SCALE) === 0) {
            return ['-'.$draft->quantity, $inStock->negated()];
        }

        // Proporción calculada de una sola vez y con precisión extra: dividir
        // primero para obtener el promedio y multiplicar después redondearía
        // dos veces.
        $share = bcdiv(
            bcmul($inStock->toString(), $draft->quantity, self::QUANTITY_SCALE + Money::SCALE),
            $available,
            Money::SCALE + 2,
        );

        return ['-'.$draft->quantity, Money::ofRounded($share)->negated()];
    }

    /**
     * Toma la fila de existencia con bloqueo, creándola la primera vez.
     *
     * El bloqueo es lo que impide que dos facturas simultáneas del mismo
     * producto lean el mismo promedio y escriban saldos incompatibles. La
     * creación va fuera del bloqueo porque no hay fila que bloquear todavía;
     * si dos procesos la crean a la vez, el índice único de
     * (warehouse_id, product_id) deja pasar solo a uno y el otro reintenta la
     * lectura, que para entonces ya existe.
     */
    private function lockStock(int $warehouseId, int $productId): InventoryStock
    {
        $find = fn (bool $lock) => InventoryStock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();

        if ($stock = $find(true)) {
            return $stock;
        }

        try {
            $stock = new InventoryStock;
            $stock->forceFill([
                'company_id' => $this->context->idOrFail(),
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'quantity' => '0',
                'total_value' => '0',
                'min_quantity' => '0',
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // Otro proceso la creó entre la lectura y la escritura.
        }

        return $find(true) ?? throw new InventoryException(
            'No se pudo abrir la existencia del producto en la bodega.'
        );
    }

    private function writeMovement(
        StockMovementDraft $draft,
        string $quantityChange,
        Money $valueChange,
        string $balanceQuantity,
        Money $balanceValue,
    ): InventoryMovement {
        // Costo unitario informativo: lo que se guarda de verdad es el valor.
        $unitCost = bccomp($draft->quantity, '0', self::QUANTITY_SCALE) === 0
            ? '0'
            : bcdiv($valueChange->absolute()->toString(), $draft->quantity, self::QUANTITY_SCALE);

        $movement = new InventoryMovement;

        $movement->forceFill([
            'company_id' => $this->context->idOrFail(),
            'warehouse_id' => $draft->warehouseId,
            'product_id' => $draft->productId,
            'date' => $draft->date->toDateString(),
            'type' => $draft->type,
            'quantity' => $quantityChange,
            'unit_cost' => $unitCost,
            'total_value' => $valueChange->toString(),
            'balance_quantity' => $balanceQuantity,
            'balance_value' => $balanceValue->toString(),
            'source_type' => $draft->sourceType,
            'source_id' => $draft->sourceId,
            'reference' => $draft->reference,
            'description' => $draft->description,
            'created_by' => Auth::id(),
        ])->save();

        return $movement;
    }
}
