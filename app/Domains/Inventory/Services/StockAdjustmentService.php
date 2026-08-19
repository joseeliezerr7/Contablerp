<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Services;

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Enums\AccountMappingKey;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Accounting\Services\AccountMappingService;
use App\Domains\Accounting\Services\DocumentSeriesService;
use App\Domains\Catalog\Models\Product;
use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Inventory\DataTransfer\StockMovementDraft;
use App\Domains\Inventory\Enums\AdjustmentReason;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\StockDocumentStatus;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Inventory\Models\StockAdjustment;
use App\Domains\Inventory\Models\StockAdjustmentItem;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Ajustes de existencias.
 *
 * Un ajuste es la única forma de mover el inventario sin una compra o una
 * venta detrás, y siempre tiene contrapartida contable: si sobra mercadería, el
 * inventario sube y el gasto baja; si falta, el inventario baja y aparece una
 * pérdida. No hay ajuste «solo de cantidad»: la mercadería vale dinero, y una
 * cantidad que cambia sin que cambie el valor descuadraría el kardex contra la
 * cuenta contable a la primera.
 *
 * ## De dónde sale el costo
 *
 * En una **salida** lo pone el promedio vigente, igual que en una venta: la
 * mercadería que falta costó lo que costó. En una **entrada** lo teclea el
 * usuario, porque si la existencia está en cero no hay promedio del que
 * sacarlo. Cuando sí hay promedio y el usuario no dice nada, se usa el
 * promedio, que es la respuesta correcta en el caso habitual: un conteo que
 * encontró unidades de más de lo mismo que ya está en bodega.
 */
final class StockAdjustmentService
{
    public const SERIES = 'stock_adjustment';

    public function __construct(
        private readonly CompanyContext $context,
        private readonly InventoryService $inventory,
        private readonly AccountingEngine $engine,
        private readonly AccountMappingService $mappings,
        private readonly DocumentSeriesService $series,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function saveDraft(array $data, array $lines): StockAdjustment
    {
        return DB::transaction(function () use ($data, $lines): StockAdjustment {
            $adjustment = new StockAdjustment;
            $adjustment->forceFill([
                ...$this->headerAttributes($data),
                'company_id' => $this->context->idOrFail(),
                'status' => StockDocumentStatus::Draft,
                'created_by' => Auth::id(),
            ])->save();

            $this->replaceLines($adjustment, $lines);

            return $adjustment->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function updateDraft(StockAdjustment $adjustment, array $data, array $lines): StockAdjustment
    {
        if (! $adjustment->isDraft()) {
            throw InventoryException::adjustmentNotDraft($adjustment);
        }

        return DB::transaction(function () use ($adjustment, $data, $lines): StockAdjustment {
            $adjustment->forceFill($this->headerAttributes($data))->save();
            $this->replaceLines($adjustment, $lines);

            return $adjustment->refresh();
        });
    }

    public function deleteDraft(StockAdjustment $adjustment): void
    {
        if (! $adjustment->isDraft()) {
            throw InventoryException::adjustmentNotDraft($adjustment);
        }

        DB::transaction(function () use ($adjustment): void {
            $adjustment->items()->delete();
            $adjustment->delete();
        });
    }

    /**
     * Aplica el ajuste: mueve el kardex y contabiliza la diferencia.
     */
    public function post(StockAdjustment $adjustment): StockAdjustment
    {
        if (! $adjustment->isDraft()) {
            throw InventoryException::adjustmentNotDraft($adjustment);
        }

        return DB::transaction(function () use ($adjustment): StockAdjustment {
            $adjustment->load(['items.product', 'warehouse']);

            if ($adjustment->items->isEmpty()) {
                throw InventoryException::emptyDocument();
            }

            $adjustment->forceFill([
                'number' => $this->series->next(self::SERIES, '*', $adjustment->branch_id, 'AJU-'),
                'status' => StockDocumentStatus::Posted,
                'posted_at' => now(),
                'posted_by' => Auth::id(),
            ])->save();

            $total = $this->applyMovements($adjustment);

            $adjustment->forceFill(['total_value' => $total->toString()])->save();

            if (! $total->isZero()) {
                $this->engine->post($this->buildJournalDraft($adjustment, $total));
            }

            $this->audit->log('posted', $adjustment, newValues: [
                'number' => $adjustment->number,
                'total_value' => $total->toString(),
            ], module: 'inventory');

            return $adjustment->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function createAndPost(array $data, array $lines): StockAdjustment
    {
        return DB::transaction(function () use ($data, $lines): StockAdjustment {
            return $this->post($this->saveDraft($data, $lines));
        });
    }

    /**
     * Anula el ajuste: devuelve las existencias y revierte la partida.
     */
    public function void(StockAdjustment $adjustment, string $reason): StockAdjustment
    {
        if (! $adjustment->isPosted()) {
            throw InventoryException::adjustmentNotPosted($adjustment);
        }

        if (trim($reason) === '') {
            throw InventoryException::emptyReason();
        }

        return DB::transaction(function () use ($adjustment, $reason): StockAdjustment {
            $adjustment->load(['items.product']);

            $entry = $adjustment->journalEntry();

            if ($entry !== null) {
                $this->voidOrReverse($entry, $reason);
            }

            // Cada línea se deshace por su propio valor, el que se aplicó.
            foreach ($adjustment->items as $item) {
                $wasIncrease = $item->isIncrease();
                $quantity = ltrim((string) $item->quantity, '-');

                $draft = $wasIncrease
                    ? StockMovementDraft::out(
                        $item->product_id, $adjustment->warehouse_id, $quantity,
                        MovementType::AdjustmentOut, now(),
                    )->valuedAt($item->valueAmount()->absolute())
                    : StockMovementDraft::in(
                        $item->product_id, $adjustment->warehouse_id, $quantity,
                        $item->valueAmount()->absolute(), MovementType::AdjustmentIn, now(),
                    );

                $this->inventory->apply(
                    $draft->fromDocument(StockAdjustment::SOURCE_TYPE, $adjustment->id, $adjustment->number)
                        ->describedAs('Anulación de ajuste')
                );
            }

            $adjustment->forceFill([
                'status' => StockDocumentStatus::Voided,
                'voided_at' => now(),
                'voided_by' => Auth::id(),
                'void_reason' => $reason,
            ])->save();

            $this->audit->log('voided', $adjustment, reason: $reason, module: 'inventory');

            return $adjustment->refresh();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Interno
    |--------------------------------------------------------------------------
    */

    /**
     * Mueve el kardex línea por línea y devuelve el valor neto del ajuste.
     */
    private function applyMovements(StockAdjustment $adjustment): Money
    {
        $total = Money::zero();

        foreach ($adjustment->items as $item) {
            $quantity = ltrim((string) $item->quantity, '-');

            if ($item->isIncrease()) {
                $value = $this->increaseValue($adjustment, $item, $quantity);

                $movement = $this->inventory->apply(
                    StockMovementDraft::in(
                        $item->product_id, $adjustment->warehouse_id, $quantity,
                        $value, MovementType::AdjustmentIn, $adjustment->date,
                    )->fromDocument(StockAdjustment::SOURCE_TYPE, $adjustment->id, $adjustment->number)
                        ->describedAs($adjustment->reason->label())
                );
            } else {
                $movement = $this->inventory->apply(
                    StockMovementDraft::out(
                        $item->product_id, $adjustment->warehouse_id, $quantity,
                        MovementType::AdjustmentOut, $adjustment->date,
                    )->fromDocument(StockAdjustment::SOURCE_TYPE, $adjustment->id, $adjustment->number)
                        ->describedAs($adjustment->reason->label())
                );
            }

            // El importe que quedó en el kardex es el que vale, no el que traía
            // la línea: en una salida lo puso el promedio.
            $item->forceFill([
                'unit_cost' => $movement->unit_cost,
                'total_value' => $movement->valueAmount()->toString(),
            ])->save();

            $total = $total->plus($movement->valueAmount());
        }

        return $total;
    }

    /**
     * Costo con el que entra una unidad sobrante.
     */
    private function increaseValue(StockAdjustment $adjustment, StockAdjustmentItem $item, string $quantity): Money
    {
        $declared = Money::of((string) $item->unit_cost);

        if ($declared->isPositive()) {
            return $declared->times($quantity)->round(Money::SCALE);
        }

        // Sin costo declarado: el promedio de la bodega. Si tampoco hay
        // existencia, el costo de referencia del producto es lo único que
        // queda.
        $average = $this->inventory->averageCost($item->product_id, $adjustment->warehouse_id);

        if (! $average->isPositive()) {
            $average = Money::of((string) ($item->product?->cost ?? '0'));
        }

        return $average->times($quantity)->round(Money::SCALE);
    }

    /**
     * Partida del ajuste.
     *
     * Un sobrante carga inventario y abona la cuenta de ajustes —es un gasto
     * que se recupera—; un faltante hace lo contrario. Un solo asiento por
     * documento, con el neto.
     */
    private function buildJournalDraft(StockAdjustment $adjustment, Money $total): JournalDraft
    {
        $inventoryAccount = $this->inventoryAccountFor($adjustment);
        $adjustmentAccount = $adjustment->adjustment_account_id
            ?? $this->mappings->resolveId(AccountMappingKey::InventoryAdjustment);

        $draft = JournalDraft::on(
            $adjustment->date,
            'Ajuste de inventario '.$adjustment->number.' — '.$adjustment->reason->label(),
        )
            ->inBranch($adjustment->branch_id)
            ->withReference($adjustment->number)
            ->fromDocument(StockAdjustment::SOURCE_TYPE, $adjustment->id);

        $amount = $total->absolute();

        if ($total->isPositive()) {
            $draft->debit($inventoryAccount, $amount, 'Sobrante de inventario')
                ->credit($adjustmentAccount, $amount, 'Ajuste de inventario');
        } else {
            $draft->debit($adjustmentAccount, $amount, 'Faltante de inventario')
                ->credit($inventoryAccount, $amount, 'Ajuste de inventario');
        }

        return $draft;
    }

    /**
     * Cuenta de inventario del ajuste.
     *
     * Se toma la del primer producto que declare una propia; si ninguno lo
     * hace, la del mapeo. Un ajuste que mezclara productos con cuentas de
     * inventario distintas quedaría mal repartido, pero es un caso que no se ha
     * visto en la práctica y que se resolvería con un ajuste por cuenta.
     */
    private function inventoryAccountFor(StockAdjustment $adjustment): int
    {
        foreach ($adjustment->items as $item) {
            if ($item->product?->inventory_account_id !== null) {
                return $item->product->inventory_account_id;
            }
        }

        return $this->mappings->resolveId(AccountMappingKey::InventoryAsset);
    }

    private function voidOrReverse(JournalEntry $entry, string $reason): void
    {
        if ($entry->period->acceptsPostings()) {
            $this->engine->void($entry, $reason);

            return;
        }

        $this->engine->reverse($entry, $reason);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function headerAttributes(array $data): array
    {
        $reason = $data['reason'] instanceof AdjustmentReason
            ? $data['reason']
            : AdjustmentReason::from((string) ($data['reason'] ?? 'count'));

        return [
            'branch_id' => $data['branch_id'],
            'warehouse_id' => $data['warehouse_id'],
            'date' => CarbonImmutable::parse($data['date'])->toDateString(),
            'reason' => $reason,
            'adjustment_account_id' => $data['adjustment_account_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function replaceLines(StockAdjustment $adjustment, array $lines): void
    {
        $adjustment->items()->delete();

        $number = 1;

        foreach ($lines as $line) {
            $quantity = (string) ($line['quantity'] ?? '0');

            if (bccomp($quantity, '0', 6) === 0) {
                continue;
            }

            $product = Product::query()->find($line['product_id'] ?? null);

            $item = new StockAdjustmentItem;
            $item->forceFill([
                'stock_adjustment_id' => $adjustment->id,
                'company_id' => $adjustment->company_id,
                'product_id' => $line['product_id'],
                'line_number' => $number++,
                'quantity' => $quantity,
                'unit_cost' => (string) ($line['unit_cost'] ?? '0'),
                'total_value' => '0',
                'description' => $line['description'] ?? $product?->name,
            ])->save();
        }
    }
}
