<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Services;

use App\Domains\Accounting\Services\DocumentSeriesService;
use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Inventory\DataTransfer\StockMovementDraft;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\StockDocumentStatus;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Inventory\Models\StockTransfer;
use App\Domains\Inventory\Models\StockTransferItem;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Traslados entre bodegas.
 *
 * **No generan partida contable.** La cuenta de inventario es una sola para
 * toda la empresa; mover mercadería de un estante a otro no cambia su saldo, y
 * un asiento que cargara y abonara la misma cuenta por el mismo importe sería
 * ruido en el libro diario sin información dentro.
 *
 * Lo que sí genera son dos movimientos de kardex por línea: una salida de la
 * bodega de origen, valorada al promedio de esa bodega, y una entrada a la de
 * destino **por ese mismo valor**. Así el costo viaja con la mercadería y la
 * bodega que recibe no la revaloriza: si se revalorizara, la suma de los
 * kardex dejaría de dar el saldo de la cuenta contable, que no se ha movido.
 */
final class StockTransferService
{
    public const SERIES = 'stock_transfer';

    public function __construct(
        private readonly CompanyContext $context,
        private readonly InventoryService $inventory,
        private readonly DocumentSeriesService $series,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function saveDraft(array $data, array $lines): StockTransfer
    {
        return DB::transaction(function () use ($data, $lines): StockTransfer {
            $transfer = new StockTransfer;
            $transfer->forceFill([
                ...$this->headerAttributes($data),
                'company_id' => $this->context->idOrFail(),
                'status' => StockDocumentStatus::Draft,
                'created_by' => Auth::id(),
            ])->save();

            $this->replaceLines($transfer, $lines);

            return $transfer->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function updateDraft(StockTransfer $transfer, array $data, array $lines): StockTransfer
    {
        if (! $transfer->isDraft()) {
            throw InventoryException::transferNotDraft($transfer);
        }

        return DB::transaction(function () use ($transfer, $data, $lines): StockTransfer {
            $transfer->forceFill($this->headerAttributes($data))->save();
            $this->replaceLines($transfer, $lines);

            return $transfer->refresh();
        });
    }

    public function deleteDraft(StockTransfer $transfer): void
    {
        if (! $transfer->isDraft()) {
            throw InventoryException::transferNotDraft($transfer);
        }

        DB::transaction(function () use ($transfer): void {
            $transfer->items()->delete();
            $transfer->delete();
        });
    }

    public function post(StockTransfer $transfer): StockTransfer
    {
        if (! $transfer->isDraft()) {
            throw InventoryException::transferNotDraft($transfer);
        }

        return DB::transaction(function () use ($transfer): StockTransfer {
            $transfer->load(['items.product']);

            if ($transfer->items->isEmpty()) {
                throw InventoryException::emptyDocument();
            }

            if ($transfer->from_warehouse_id === $transfer->to_warehouse_id) {
                throw InventoryException::sameWarehouse();
            }

            $transfer->forceFill([
                'number' => $this->series->next(self::SERIES, '*', $transfer->branch_id, 'TRA-'),
                'status' => StockDocumentStatus::Posted,
                'posted_at' => now(),
                'posted_by' => Auth::id(),
            ])->save();

            $total = $this->move(
                $transfer,
                $transfer->from_warehouse_id,
                $transfer->to_warehouse_id,
                'Traslado a otra bodega',
                persistCost: true,
            );

            $transfer->forceFill(['total_value' => $total->toString()])->save();

            $this->audit->log('posted', $transfer, newValues: [
                'number' => $transfer->number,
                'total_value' => $total->toString(),
            ], module: 'inventory');

            return $transfer->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function createAndPost(array $data, array $lines): StockTransfer
    {
        return DB::transaction(function () use ($data, $lines): StockTransfer {
            return $this->post($this->saveDraft($data, $lines));
        });
    }

    /**
     * Anula el traslado devolviendo la mercadería a su bodega de origen, por el
     * mismo valor con el que viajó.
     */
    public function void(StockTransfer $transfer, string $reason): StockTransfer
    {
        if (! $transfer->isPosted()) {
            throw InventoryException::transferNotPosted($transfer);
        }

        if (trim($reason) === '') {
            throw InventoryException::emptyReason();
        }

        return DB::transaction(function () use ($transfer, $reason): StockTransfer {
            $transfer->load(['items.product']);

            $this->move(
                $transfer,
                $transfer->to_warehouse_id,
                $transfer->from_warehouse_id,
                'Anulación de traslado',
                persistCost: false,
            );

            $transfer->forceFill([
                'status' => StockDocumentStatus::Voided,
                'voided_at' => now(),
                'voided_by' => Auth::id(),
                'void_reason' => $reason,
            ])->save();

            $this->audit->log('voided', $transfer, reason: $reason, module: 'inventory');

            return $transfer->refresh();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Interno
    |--------------------------------------------------------------------------
    */

    /**
     * Saca de una bodega y mete en otra por el mismo valor.
     *
     * Al anular, el valor que vuelve es el que hay en la bodega de destino en
     * ese momento, no el del traslado original. Es deliberado: si mientras la
     * mercadería estuvo allá entró más al mismo producto, lo que se devuelve es
     * una porción del promedio mezclado, y forzar el valor original dejaría
     * descuadrada la bodega de destino.
     */
    private function move(
        StockTransfer $transfer,
        int $fromWarehouse,
        int $toWarehouse,
        string $description,
        bool $persistCost,
    ): Money {
        $total = Money::zero();

        foreach ($transfer->items as $item) {
            $quantity = (string) $item->quantity;

            $out = $this->inventory->apply(
                StockMovementDraft::out(
                    $item->product_id, $fromWarehouse, $quantity,
                    MovementType::TransferOut, $transfer->date,
                )->fromDocument(StockTransfer::SOURCE_TYPE, $transfer->id, $transfer->number)
                    ->describedAs($description)
            );

            $value = $out->valueAmount()->absolute();

            $this->inventory->apply(
                StockMovementDraft::in(
                    $item->product_id, $toWarehouse, $quantity,
                    $value, MovementType::TransferIn, $transfer->date,
                )->fromDocument(StockTransfer::SOURCE_TYPE, $transfer->id, $transfer->number)
                    ->describedAs($description)
            );

            if ($persistCost) {
                $item->forceFill([
                    'unit_cost' => $out->unit_cost,
                    'total_value' => $value->toString(),
                ])->save();
            }

            $total = $total->plus($value);
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function headerAttributes(array $data): array
    {
        if ((int) $data['from_warehouse_id'] === (int) $data['to_warehouse_id']) {
            throw InventoryException::sameWarehouse();
        }

        return [
            'branch_id' => $data['branch_id'],
            'from_warehouse_id' => $data['from_warehouse_id'],
            'to_warehouse_id' => $data['to_warehouse_id'],
            'date' => CarbonImmutable::parse($data['date'])->toDateString(),
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function replaceLines(StockTransfer $transfer, array $lines): void
    {
        $transfer->items()->delete();

        $number = 1;

        foreach ($lines as $line) {
            $quantity = (string) ($line['quantity'] ?? '0');

            if (bccomp($quantity, '0', 6) <= 0) {
                continue;
            }

            $item = new StockTransferItem;
            $item->forceFill([
                'stock_transfer_id' => $transfer->id,
                'company_id' => $transfer->company_id,
                'product_id' => $line['product_id'],
                'line_number' => $number++,
                'quantity' => $quantity,
                'unit_cost' => '0',
                'total_value' => '0',
            ])->save();
        }
    }
}
