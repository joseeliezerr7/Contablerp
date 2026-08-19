<?php

declare(strict_types=1);

use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Exceptions\InsufficientStockException;
use App\Domains\Inventory\Models\InventoryMovement;
use App\Domains\Inventory\Services\InventoryService;
use App\Domains\Purchases\Models\Purchase;
use App\Domains\Purchases\Services\PurchaseService;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleService;
use App\Support\Money;
use Carbon\CarbonImmutable;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();

    $this->sales = app(SaleService::class);
    $this->purchases = app(PurchaseService::class);
    $this->inventory = app(InventoryService::class);

    $this->branch = mainBranch();
    $this->warehouse = warehouse();
    $this->bank = account('1.1.02.01');
    $this->supplier = makeSupplier();
    $this->customer = makeCustomer(['credit_limit' => '900000.00', 'credit_days' => 30]);
    $this->product = makeProduct('1500.00', tracked: true);
});

/**
 * Compra que ingresa mercadería a la bodega.
 */
function buyInto(object $ctx, string $quantity, string $unitPrice, string $invoice): void
{
    $ctx->purchases->createAndReceive([
        'branch_id' => $ctx->branch->id,
        'warehouse_id' => $ctx->warehouse->id,
        'supplier_id' => $ctx->supplier->id,
        'supplier_invoice_number' => $invoice,
        'date' => CarbonImmutable::parse('2026-03-01')->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $ctx->product->id, 'quantity' => $quantity, 'unit_price' => $unitPrice]]);
}

/**
 * Venta de contado que despacha de la bodega.
 */
function sellFrom(object $ctx, string $quantity, string $unitPrice = '1500.00'): Sale
{
    return $ctx->sales->createAndIssue([
        'branch_id' => $ctx->branch->id,
        'warehouse_id' => $ctx->warehouse->id,
        'customer_id' => $ctx->customer->id,
        'date' => CarbonImmutable::parse('2026-03-10')->toDateString(),
        'payment_condition' => PaymentCondition::Cash,
        'deposit_account_id' => $ctx->bank->id,
    ], [['product_id' => $ctx->product->id, 'quantity' => $quantity, 'unit_price' => $unitPrice]]);
}

/*
|--------------------------------------------------------------------------
| La compra alimenta el kardex
|--------------------------------------------------------------------------
*/

it('ingresa al kardex lo que la compra cargó a inventario', function () {
    buyInto($this, '10', '800.00', 'FAC-INV-01');

    $stock = $this->inventory->stockFor($this->product->id, $this->warehouse->id);

    expect($stock->quantity)->toBe('10.000000')
        ->and($stock->total_value)->toBe('8000.0000')
        ->and($stock->average_cost)->toBe('800.000000');
});

it('ingresa al kardex el costo neto de descuentos, no el bruto', function () {
    $this->purchases->createAndReceive([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $this->warehouse->id,
        'supplier_id' => $this->supplier->id,
        'supplier_invoice_number' => 'FAC-INV-02',
        'date' => '2026-03-01',
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [[
        'product_id' => $this->product->id,
        'quantity' => '10',
        'unit_price' => '1000.00',
        'discount_rate' => '20',
    ]]);

    // 10 000 − 20 % = 8 000: la mercadería costó 800, no 1 000.
    expect($this->inventory->averageCost($this->product->id, $this->warehouse->id)->toString())
        ->toBe('800.0000');
});

it('no toca el kardex con un producto sin control de existencias', function () {
    $servicio = makeProduct('500.00');

    $this->purchases->createAndReceive([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $this->warehouse->id,
        'supplier_id' => $this->supplier->id,
        'supplier_invoice_number' => 'FAC-INV-03',
        'date' => '2026-03-01',
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $servicio->id, 'quantity' => '1', 'unit_price' => '500.00']]);

    expect(InventoryMovement::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| La venta descarga y cuesta
|--------------------------------------------------------------------------
*/

it('descarga la bodega al emitir la factura', function () {
    buyInto($this, '10', '800.00', 'FAC-INV-10');

    sellFrom($this, '4');

    expect($this->inventory->availableQuantity($this->product->id, $this->warehouse->id))
        ->toBe('6.000000');
});

it('asienta el costo de ventas con el costo del kardex, no con el del catálogo', function () {
    // El producto trae un costo de referencia distinto del real.
    $this->product->forceFill(['cost' => '999.00'])->save();

    buyInto($this, '10', '800.00', 'FAC-INV-11');

    $venta = sellFrom($this, '4');
    $lines = $venta->journalEntry()->lines->keyBy('account_id');

    expect($lines[account('5.1.01')->id]->debitAmount()->toString())->toBe('3200.0000')
        ->and($lines[account('1.1.04.01')->id]->creditAmount()->toString())->toBe('3200.0000');
});

it('costea al promedio ponderado de las dos compras', function () {
    buyInto($this, '10', '800.00', 'FAC-INV-12');
    buyInto($this, '10', '1200.00', 'FAC-INV-13');

    // (8000 + 12000) / 20 = 1000
    $venta = sellFrom($this, '5');

    expect($venta->items->first()->costAmount()->toString())->toBe('5000.0000')
        ->and($this->inventory->averageCost($this->product->id, $this->warehouse->id)->toString())
        ->toBe('1000.0000');
});

it('deja la partida cuadrada con el costo de ventas incluido', function () {
    buyInto($this, '10', '800.00', 'FAC-INV-14');

    $venta = sellFrom($this, '4');
    $entry = $venta->journalEntry();

    expect($entry->isBalanced())->toBeTrue()
        // Ingreso 6 000 + ISV 900 al haber, y además costo 3 200 al debe
        // contra inventario al haber.
        ->and($entry->lines)->toHaveCount(5);
});

it('acredita a inventario exactamente lo que el kardex descargó', function () {
    // 3 unidades por 100 no reparte: el promedio es 33.333333…
    buyInto($this, '3', '100.00', 'FAC-INV-15');

    $venta = sellFrom($this, '1');

    $movimiento = InventoryMovement::query()->where('type', MovementType::Sale)->sole();
    $lineaInventario = $venta->journalEntry()->lines
        ->firstWhere('account_id', account('1.1.04.01')->id);

    expect($lineaInventario->creditAmount()->toString())
        ->toBe($movimiento->valueAmount()->absolute()->toString());
});

it('bloquea la factura cuando no alcanza la existencia', function () {
    buyInto($this, '3', '800.00', 'FAC-INV-16');

    expect(fn () => sellFrom($this, '5'))
        ->toThrow(InsufficientStockException::class, 'No hay existencia suficiente');
});

it('no deja rastro de la factura rechazada por falta de existencia', function () {
    buyInto($this, '3', '800.00', 'FAC-INV-17');

    try {
        sellFrom($this, '5');
    } catch (InsufficientStockException) {
        // Esperado.
    }

    // Ni factura emitida, ni movimiento de salida, ni existencia tocada.
    expect(Sale::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->where('type', MovementType::Sale)->count())->toBe(0)
        ->and($this->inventory->availableQuantity($this->product->id, $this->warehouse->id))
        ->toBe('3.000000');
});

it('exige bodega cuando la factura despacha mercadería', function () {
    buyInto($this, '10', '800.00', 'FAC-INV-18');

    expect(fn () => $this->sales->createAndIssue([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => '2026-03-10',
        'payment_condition' => PaymentCondition::Cash,
        'deposit_account_id' => $this->bank->id,
    ], [['product_id' => $this->product->id, 'quantity' => '1', 'unit_price' => '1500.00']]))
        ->toThrow(SalesException::class, 'hay que indicar la bodega');
});

it('factura servicios sin pedir bodega', function () {
    $servicio = makeProduct('2500.00');

    $venta = $this->sales->createAndIssue([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => '2026-03-10',
        'payment_condition' => PaymentCondition::Cash,
        'deposit_account_id' => $this->bank->id,
    ], [['product_id' => $servicio->id, 'quantity' => '1', 'unit_price' => '2500.00']]);

    expect($venta->isIssued())->toBeTrue()
        ->and($venta->items->first()->costAmount()->isZero())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Anulaciones
|--------------------------------------------------------------------------
*/

it('reingresa la mercadería al anular la factura', function () {
    buyInto($this, '10', '800.00', 'FAC-INV-20');

    $venta = sellFrom($this, '4');
    $this->sales->void($venta, 'Cliente devolvió la mercadería');

    $stock = $this->inventory->stockFor($this->product->id, $this->warehouse->id);

    expect($stock->quantity)->toBe('10.000000')
        ->and($stock->total_value)->toBe('8000.0000');
});

it('reingresa por el mismo costo con el que salió, no por el promedio de hoy', function () {
    buyInto($this, '10', '800.00', 'FAC-INV-21');

    $venta = sellFrom($this, '5');            // sale a 800: quedan 5 por 4 000

    buyInto($this, '5', '1600.00', 'FAC-INV-22'); // entra caro: 10 por 12 000, promedio 1 200

    $this->sales->void($venta, 'Devolución del cliente');

    $stock = $this->inventory->stockFor($this->product->id, $this->warehouse->id);

    // Vuelven 5 unidades por los 4 000 que costaron, no por 6 000.
    expect($stock->quantity)->toBe('15.000000')
        ->and($stock->total_value)->toBe('16000.0000');
});

it('devuelve la mercadería al proveedor al anular la compra', function () {
    buyInto($this, '10', '800.00', 'FAC-INV-23');

    $compra = Purchase::query()->sole();
    $this->purchases->void($compra, 'Mercadería equivocada');

    $stock = $this->inventory->stockFor($this->product->id, $this->warehouse->id);

    expect($stock->quantity)->toBe('0.000000')
        ->and($stock->total_value)->toBe('0.0000');
});

it('impide anular la compra cuya mercadería ya se vendió', function () {
    buyInto($this, '10', '800.00', 'FAC-INV-24');
    sellFrom($this, '10');

    $compra = Purchase::query()->sole();

    expect(fn () => $this->purchases->void($compra, 'Intento de anulación'))
        ->toThrow(InsufficientStockException::class);
});

/*
|--------------------------------------------------------------------------
| La invariante de la fase, en pequeño
|--------------------------------------------------------------------------
*/

it('mantiene el kardex igual al saldo de la cuenta de inventario', function () {
    buyInto($this, '7', '333.33', 'FAC-INV-30');
    sellFrom($this, '2');
    buyInto($this, '11', '81.93', 'FAC-INV-31');
    sellFrom($this, '9');

    $saldoContable = ledgerBalanceOf('1.1.04.01');

    expect($this->inventory->totalValue()->toString())->toBe($saldoContable->toString())
        ->and($this->inventory->totalValue()->isPositive())->toBeTrue();
});

it('vuelve a cuadrar después de anularlo todo', function () {
    buyInto($this, '10', '800.00', 'FAC-INV-40');
    $venta = sellFrom($this, '4');

    $this->sales->void($venta, 'Anulada en la prueba');

    $compra = Purchase::query()->sole();
    $this->purchases->void($compra, 'Anulada en la prueba');

    expect($this->inventory->totalValue()->isZero())->toBeTrue()
        ->and(ledgerBalanceOf('1.1.04.01')->isZero())->toBeTrue()
        ->and(Money::sum(
            InventoryMovement::query()->get()->map(fn (InventoryMovement $m) => $m->valueAmount())->all()
        )->isZero())->toBeTrue();
});
