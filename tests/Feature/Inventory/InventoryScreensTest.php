<?php

declare(strict_types=1);

use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Inventory\Enums\AdjustmentReason;
use App\Domains\Inventory\Enums\StockDocumentStatus;
use App\Domains\Inventory\Models\StockAdjustment;
use App\Domains\Inventory\Models\StockTransfer;
use App\Domains\Inventory\Services\InventoryService;
use App\Domains\Inventory\Services\StockAdjustmentService;
use App\Domains\Purchases\Services\PurchaseService;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Livewire\Inventory\AdjustmentForm;
use App\Livewire\Inventory\AdjustmentIndex;
use App\Livewire\Inventory\KardexView;
use App\Livewire\Inventory\StockIndex;
use App\Livewire\Inventory\TransferForm;
use App\Livewire\Inventory\TransferIndex;
use Livewire\Livewire;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();

    $this->purchases = app(PurchaseService::class);
    $this->inventory = app(InventoryService::class);

    $this->branch = mainBranch();
    $this->warehouse = warehouse();
    $this->otherWarehouse = warehouse('BOD2');
    $this->supplier = makeSupplier();
    $this->product = makeProduct('1500.00', tracked: true);

    $this->purchases->createAndReceive([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $this->warehouse->id,
        'supplier_id' => $this->supplier->id,
        'supplier_invoice_number' => 'FAC-UI-01',
        'date' => '2026-03-01',
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $this->product->id, 'quantity' => '20', 'unit_price' => '400.00']]);
});

/*
|--------------------------------------------------------------------------
| Existencias y kardex
|--------------------------------------------------------------------------
*/

it('muestra las existencias con su valor', function () {
    Livewire::test(StockIndex::class)
        ->assertOk()
        ->assertSee($this->product->code)
        ->assertSee('8,000.00');
});

it('filtra las existencias por bodega', function () {
    Livewire::test(StockIndex::class)
        ->set('warehouseFilter', (string) $this->otherWarehouse->id)
        ->assertDontSee($this->product->code);
});

it('guarda los puntos de reorden', function () {
    $stock = $this->inventory->stockFor($this->product->id, $this->warehouse->id);

    Livewire::test(StockIndex::class)
        ->call('editReorder', $stock->id)
        ->set('minQuantity', '30')
        ->call('saveReorder')
        ->assertHasNoErrors();

    expect($stock->refresh()->isBelowMinimum())->toBeTrue();
});

it('muestra el kardex del producto elegido', function () {
    Livewire::test(KardexView::class)
        ->set('productId', (string) $this->product->id)
        ->assertOk()
        ->assertSee('Compra');
});

it('no pide producto para abrir el kardex vacío', function () {
    Livewire::test(KardexView::class)
        ->assertOk()
        ->assertSee('Elige un producto');
});

/*
|--------------------------------------------------------------------------
| Ajustes
|--------------------------------------------------------------------------
*/

it('captura y aplica un ajuste desde la pantalla', function () {
    Livewire::test(AdjustmentForm::class)
        ->set('warehouse_id', $this->warehouse->id)
        ->set('date', '2026-03-15')
        ->set('reason', AdjustmentReason::Loss->value)
        ->set('lines', [['product_id' => $this->product->id, 'quantity' => '-3', 'unit_cost' => '0']])
        ->call('saveAndPost')
        ->assertHasNoErrors()
        ->assertRedirect(route('inventory.adjustments.index'));

    expect($this->inventory->availableQuantity($this->product->id, $this->warehouse->id))
        ->toBe('17.000000');
});

it('muestra la existencia actual al elegir el producto', function () {
    // Es el dato contra el que el usuario compara su conteo, y depende del
    // hook de propiedad anidada de Livewire.
    Livewire::test(AdjustmentForm::class)
        ->set('warehouse_id', $this->warehouse->id)
        ->set('lines.0.product_id', $this->product->id)
        ->assertSet('lines.0.on_hand', '20.000000');
});

it('muestra el error de existencia insuficiente en vez de reventar', function () {
    Livewire::test(AdjustmentForm::class)
        ->set('warehouse_id', $this->warehouse->id)
        ->set('lines', [['product_id' => $this->product->id, 'quantity' => '-99', 'unit_cost' => '0']])
        ->call('saveAndPost')
        ->assertHasErrors('lines');
});

it('rechaza un ajuste sin producto', function () {
    Livewire::test(AdjustmentForm::class)
        ->set('lines', [['product_id' => null, 'quantity' => '5', 'unit_cost' => '0']])
        ->call('saveAndPost')
        ->assertHasErrors('lines.0.product_id');
});

it('lista los ajustes', function () {
    app(StockAdjustmentService::class)->createAndPost([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $this->warehouse->id,
        'date' => '2026-03-15',
        'reason' => AdjustmentReason::Damage,
    ], [['product_id' => $this->product->id, 'quantity' => '-2']]);

    Livewire::test(AdjustmentIndex::class)
        ->assertOk()
        ->assertSee('AJU-000001')
        ->assertSee('Producto dañado');
});

/*
|--------------------------------------------------------------------------
| Traslados
|--------------------------------------------------------------------------
*/

it('captura y aplica un traslado desde la pantalla', function () {
    Livewire::test(TransferForm::class)
        ->set('from_warehouse_id', $this->warehouse->id)
        ->set('to_warehouse_id', $this->otherWarehouse->id)
        ->set('date', '2026-03-20')
        ->set('lines', [['product_id' => $this->product->id, 'quantity' => '5']])
        ->call('saveAndPost')
        ->assertHasNoErrors()
        ->assertRedirect(route('inventory.transfers.index'));

    expect($this->inventory->availableQuantity($this->product->id, $this->otherWarehouse->id))
        ->toBe('5.000000');
});

it('rechaza trasladar a la misma bodega desde la pantalla', function () {
    Livewire::test(TransferForm::class)
        ->set('from_warehouse_id', $this->warehouse->id)
        ->set('to_warehouse_id', $this->warehouse->id)
        ->set('lines', [['product_id' => $this->product->id, 'quantity' => '1']])
        ->call('saveAndPost')
        ->assertHasErrors('to_warehouse_id');
});

it('refresca las existencias mostradas al cambiar la bodega de origen', function () {
    Livewire::test(TransferForm::class)
        ->set('from_warehouse_id', $this->warehouse->id)
        ->set('lines.0.product_id', $this->product->id)
        ->assertSet('lines.0.on_hand', '20.000000')
        ->set('from_warehouse_id', $this->otherWarehouse->id)
        ->assertSet('lines.0.on_hand', '0.000000');
});

it('lista los traslados', function () {
    Livewire::test(TransferIndex::class)->assertOk();
});

/*
|--------------------------------------------------------------------------
| Permisos por rol
|--------------------------------------------------------------------------
*/

it('deja al contador capturar y aprobar ajustes', function () {
    // El contador de una empresa pequeña hace las dos cosas; bloquearle la
    // captura dejaba el módulo inalcanzable para él.
    Livewire::test(AdjustmentForm::class)->assertOk();
    Livewire::test(TransferForm::class)->assertOk();
});

it('deja al bodeguero capturar pero no aprobar el ajuste', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::WAREHOUSE);

    Livewire::test(AdjustmentForm::class)->assertOk();

    $adjustment = new StockAdjustment;
    $adjustment->forceFill([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'warehouse_id' => $this->warehouse->id,
        'date' => '2026-03-15',
        'reason' => AdjustmentReason::Count,
        'status' => StockDocumentStatus::Draft,
    ])->save();

    expect(auth()->user()->can('post', $adjustment))->toBeFalse()
        ->and(auth()->user()->can('update', $adjustment))->toBeTrue();
});

it('deja al bodeguero aplicar traslados, que no tocan la contabilidad', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::WAREHOUSE);

    $transfer = new StockTransfer;
    $transfer->forceFill([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'from_warehouse_id' => $this->warehouse->id,
        'to_warehouse_id' => $this->otherWarehouse->id,
        'date' => '2026-03-20',
        'status' => StockDocumentStatus::Draft,
    ])->save();

    expect(auth()->user()->can('post', $transfer))->toBeTrue();
});

it('niega el inventario al vendedor salvo consultar existencias', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::SALESPERSON);

    Livewire::test(StockIndex::class)->assertOk();

    expect(auth()->user()->can('viewAny', StockAdjustment::class))->toBeFalse()
        ->and(auth()->user()->can('create', StockTransfer::class))->toBeFalse();
});
