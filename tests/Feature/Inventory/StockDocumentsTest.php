<?php

declare(strict_types=1);

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Inventory\Enums\AdjustmentReason;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Exceptions\InsufficientStockException;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Inventory\Models\InventoryMovement;
use App\Domains\Inventory\Models\StockAdjustment;
use App\Domains\Inventory\Models\StockTransfer;
use App\Domains\Inventory\Services\InventoryService;
use App\Domains\Inventory\Services\StockAdjustmentService;
use App\Domains\Inventory\Services\StockTransferService;
use App\Domains\Purchases\Services\PurchaseService;
use App\Domains\Sales\Enums\PaymentCondition;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();

    $this->adjustments = app(StockAdjustmentService::class);
    $this->transfers = app(StockTransferService::class);
    $this->inventory = app(InventoryService::class);
    $this->purchases = app(PurchaseService::class);

    $this->branch = mainBranch();
    $this->warehouse = warehouse();
    $this->otherWarehouse = warehouse('BOD2');
    $this->supplier = makeSupplier();
    $this->product = makeProduct('1500.00', tracked: true);
});

/**
 * Deja existencia en la bodega principal mediante una compra real.
 */
function stockUp(object $ctx, string $quantity, string $unitPrice, string $invoice): void
{
    $ctx->purchases->createAndReceive([
        'branch_id' => $ctx->branch->id,
        'warehouse_id' => $ctx->warehouse->id,
        'supplier_id' => $ctx->supplier->id,
        'supplier_invoice_number' => $invoice,
        'date' => '2026-03-01',
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $ctx->product->id, 'quantity' => $quantity, 'unit_price' => $unitPrice]]);
}

/**
 * @param  array<int, array<string, mixed>>  $lines
 */
function adjust(object $ctx, array $lines, AdjustmentReason $reason = AdjustmentReason::Count): StockAdjustment
{
    return $ctx->adjustments->createAndPost([
        'branch_id' => $ctx->branch->id,
        'warehouse_id' => $ctx->warehouse->id,
        'date' => '2026-03-15',
        'reason' => $reason,
    ], $lines);
}

/*
|--------------------------------------------------------------------------
| Ajustes
|--------------------------------------------------------------------------
*/

it('registra un faltante al promedio vigente y lo contabiliza', function () {
    stockUp($this, '10', '800.00', 'FAC-AJU-01');

    $ajuste = adjust($this, [['product_id' => $this->product->id, 'quantity' => '-2']], AdjustmentReason::Loss);

    $lines = $ajuste->journalEntry()->lines->keyBy('account_id');

    expect($ajuste->number)->toBe('AJU-000001')
        ->and($ajuste->valueAmount()->toString())->toBe('-1600.0000')
        ->and($this->inventory->availableQuantity($this->product->id, $this->warehouse->id))->toBe('8.000000')
        // La pérdida al debe, el inventario al haber.
        ->and($lines[account('5.1.03')->id]->debitAmount()->toString())->toBe('1600.0000')
        ->and($lines[account('1.1.04.01')->id]->creditAmount()->toString())->toBe('1600.0000');
});

it('registra un sobrante al promedio cuando no se declara costo', function () {
    stockUp($this, '10', '800.00', 'FAC-AJU-02');

    $ajuste = adjust($this, [['product_id' => $this->product->id, 'quantity' => '3']]);

    $lines = $ajuste->journalEntry()->lines->keyBy('account_id');

    expect($ajuste->valueAmount()->toString())->toBe('2400.0000')
        ->and($this->inventory->averageCost($this->product->id, $this->warehouse->id)->toString())->toBe('800.0000')
        // El inventario sube y el gasto de ajustes se recupera.
        ->and($lines[account('1.1.04.01')->id]->debitAmount()->toString())->toBe('2400.0000')
        ->and($lines[account('5.1.03')->id]->creditAmount()->toString())->toBe('2400.0000');
});

it('usa el costo declarado para cargar existencia inicial sin promedio', function () {
    $ajuste = adjust(
        $this,
        [['product_id' => $this->product->id, 'quantity' => '20', 'unit_cost' => '450.00']],
        AdjustmentReason::Opening,
    );

    expect($ajuste->valueAmount()->toString())->toBe('9000.0000')
        ->and($this->inventory->averageCost($this->product->id, $this->warehouse->id)->toString())->toBe('450.0000');
});

it('bloquea el faltante que dejaría la existencia en negativo', function () {
    stockUp($this, '3', '800.00', 'FAC-AJU-03');

    expect(fn () => adjust($this, [['product_id' => $this->product->id, 'quantity' => '-5']]))
        ->toThrow(InsufficientStockException::class);
});

it('devuelve las existencias al anular el ajuste', function () {
    stockUp($this, '10', '800.00', 'FAC-AJU-04');

    $ajuste = adjust($this, [['product_id' => $this->product->id, 'quantity' => '-4']], AdjustmentReason::Damage);
    $this->adjustments->void($ajuste, 'Se encontró la mercadería');

    expect($this->inventory->availableQuantity($this->product->id, $this->warehouse->id))->toBe('10.000000')
        ->and($this->inventory->totalValue()->toString())->toBe('8000.0000')
        ->and($ajuste->refresh()->isVoided())->toBeTrue();
});

it('mantiene el kardex igual a la cuenta contable después de ajustar', function () {
    stockUp($this, '10', '800.00', 'FAC-AJU-05');
    adjust($this, [['product_id' => $this->product->id, 'quantity' => '-3']], AdjustmentReason::Damage);
    adjust($this, [['product_id' => $this->product->id, 'quantity' => '2']]);

    expect($this->inventory->totalValue()->toString())
        ->toBe(ledgerBalanceOf('1.1.04.01')->toString());
});

it('rechaza aplicar un ajuste sin líneas', function () {
    expect(fn () => adjust($this, []))->toThrow(InventoryException::class, 'no tiene líneas');
});

/*
|--------------------------------------------------------------------------
| Traslados
|--------------------------------------------------------------------------
*/

it('mueve la mercadería de una bodega a otra con su costo', function () {
    stockUp($this, '10', '800.00', 'FAC-TRA-01');

    $traslado = $this->transfers->createAndPost([
        'branch_id' => $this->branch->id,
        'from_warehouse_id' => $this->warehouse->id,
        'to_warehouse_id' => $this->otherWarehouse->id,
        'date' => '2026-03-20',
    ], [['product_id' => $this->product->id, 'quantity' => '4']]);

    expect($traslado->number)->toBe('TRA-000001')
        ->and($traslado->valueAmount()->toString())->toBe('3200.0000')
        ->and($this->inventory->availableQuantity($this->product->id, $this->warehouse->id))->toBe('6.000000')
        ->and($this->inventory->availableQuantity($this->product->id, $this->otherWarehouse->id))->toBe('4.000000')
        // El costo viaja con la mercadería: la bodega de destino no revaloriza.
        ->and($this->inventory->averageCost($this->product->id, $this->otherWarehouse->id)->toString())->toBe('800.0000');
});

it('no genera partida contable por un traslado', function () {
    stockUp($this, '10', '800.00', 'FAC-TRA-02');

    $antes = ledgerBalanceOf('1.1.04.01');

    $this->transfers->createAndPost([
        'branch_id' => $this->branch->id,
        'from_warehouse_id' => $this->warehouse->id,
        'to_warehouse_id' => $this->otherWarehouse->id,
        'date' => '2026-03-20',
    ], [['product_id' => $this->product->id, 'quantity' => '4']]);

    // Ni el saldo de inventario cambia, ni aparece una partida del traslado.
    expect(ledgerBalanceOf('1.1.04.01')->toString())->toBe($antes->toString())
        ->and(JournalEntry::query()
            ->where('source_type', StockTransfer::SOURCE_TYPE)->count())->toBe(0);
});

it('deja el valor total del inventario intacto tras un traslado', function () {
    stockUp($this, '10', '800.00', 'FAC-TRA-03');

    $this->transfers->createAndPost([
        'branch_id' => $this->branch->id,
        'from_warehouse_id' => $this->warehouse->id,
        'to_warehouse_id' => $this->otherWarehouse->id,
        'date' => '2026-03-20',
    ], [['product_id' => $this->product->id, 'quantity' => '7']]);

    expect($this->inventory->totalValue()->toString())->toBe('8000.0000')
        ->and($this->inventory->totalValue()->toString())->toBe(ledgerBalanceOf('1.1.04.01')->toString());
});

it('escribe dos movimientos de kardex por línea trasladada', function () {
    stockUp($this, '10', '800.00', 'FAC-TRA-04');

    $this->transfers->createAndPost([
        'branch_id' => $this->branch->id,
        'from_warehouse_id' => $this->warehouse->id,
        'to_warehouse_id' => $this->otherWarehouse->id,
        'date' => '2026-03-20',
    ], [['product_id' => $this->product->id, 'quantity' => '4']]);

    expect(InventoryMovement::query()->where('type', MovementType::TransferOut)->count())->toBe(1)
        ->and(InventoryMovement::query()->where('type', MovementType::TransferIn)->count())->toBe(1);
});

it('devuelve la mercadería a su bodega al anular el traslado', function () {
    stockUp($this, '10', '800.00', 'FAC-TRA-05');

    $traslado = $this->transfers->createAndPost([
        'branch_id' => $this->branch->id,
        'from_warehouse_id' => $this->warehouse->id,
        'to_warehouse_id' => $this->otherWarehouse->id,
        'date' => '2026-03-20',
    ], [['product_id' => $this->product->id, 'quantity' => '4']]);

    $this->transfers->void($traslado, 'Traslado equivocado');

    expect($this->inventory->availableQuantity($this->product->id, $this->warehouse->id))->toBe('10.000000')
        ->and($this->inventory->availableQuantity($this->product->id, $this->otherWarehouse->id))->toBe('0.000000')
        ->and($this->inventory->totalValue()->toString())->toBe('8000.0000');
});

it('rechaza trasladar a la misma bodega', function () {
    expect(fn () => $this->transfers->createAndPost([
        'branch_id' => $this->branch->id,
        'from_warehouse_id' => $this->warehouse->id,
        'to_warehouse_id' => $this->warehouse->id,
        'date' => '2026-03-20',
    ], [['product_id' => $this->product->id, 'quantity' => '1']]))
        ->toThrow(InventoryException::class, 'no pueden ser la misma');
});

it('bloquea el traslado sin existencia en la bodega de origen', function () {
    stockUp($this, '2', '800.00', 'FAC-TRA-06');

    expect(fn () => $this->transfers->createAndPost([
        'branch_id' => $this->branch->id,
        'from_warehouse_id' => $this->warehouse->id,
        'to_warehouse_id' => $this->otherWarehouse->id,
        'date' => '2026-03-20',
    ], [['product_id' => $this->product->id, 'quantity' => '5']]))
        ->toThrow(InsufficientStockException::class);
});
