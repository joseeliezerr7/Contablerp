<?php

declare(strict_types=1);

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Catalog\Models\Product;
use App\Domains\Fiscal\Enums\FiscalDocumentType;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Models\InventoryMovement;
use App\Domains\Inventory\Models\InventoryStock;
use App\Domains\Purchases\Services\PurchaseService;
use App\Domains\Receivables\Enums\ReceivableStatus;
use App\Domains\Sales\Enums\CreditNoteReason;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\CreditNote;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\CreditNoteService;
use App\Domains\Sales\Services\SaleService;
use App\Support\Money;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->sales = app(SaleService::class);
    $this->service = app(CreditNoteService::class);
    $this->branch = mainBranch();
    $this->warehouse = warehouse();
    $this->cashAccount = account('1.1.02.01');

    // La nota de crédito lleva su propia autorización del SAR.
    withFiscalAuthorization($this->company, FiscalDocumentType::CreditNote);
});

/**
 * Factura al crédito de un producto con existencias, comprado antes para que el
 * kardex tenga un costo real que devolver.
 *
 * @return array{0: Sale, 1: Product}
 */
function creditSaleWithStock(object $ctx, string $quantity = '10', string $price = '100.00'): array
{
    $customer = makeCustomer(['credit_limit' => '900000.00', 'credit_days' => 30]);
    $product = makeProduct($price, tracked: true);
    $supplier = makeSupplier();

    app(PurchaseService::class)->createAndReceive([
        'branch_id' => $ctx->branch->id,
        'warehouse_id' => $ctx->warehouse->id,
        'supplier_id' => $supplier->id,
        'supplier_invoice_number' => 'COMPRA-'.$product->id,
        'date' => now()->subDays(5)->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $product->id, 'quantity' => '50', 'unit_price' => '60.00']]);

    $sale = $ctx->sales->createAndIssue([
        'branch_id' => $ctx->branch->id,
        'warehouse_id' => $ctx->warehouse->id,
        'customer_id' => $customer->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [
        ['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $price, 'tax_id' => tax()->id],
    ]);

    return [$sale, $product];
}

/*
|--------------------------------------------------------------------------
| Numeración fiscal
|--------------------------------------------------------------------------
*/

it('numera la nota con su propia autorización, no con la de la factura', function () {
    [$sale] = creditSaleWithStock($this);

    $note = $this->service->saveDraft($sale, [
        'date' => now()->toDateString(),
        'reason' => CreditNoteReason::Return,
        'description' => 'El cliente devolvió 2 unidades por defecto de fábrica',
    ], [
        ['sale_item_id' => $sale->items->first()->id, 'quantity' => '2'],
    ]);

    $note = $this->service->issue($note);

    // Tipo 03 y correlativo propio: la factura iba por el 01.
    expect($sale->number)->toBe('000-001-01-00000001')
        ->and($note->number)->toBe('000-001-03-00000001')
        ->and($note->cai)->not->toBe($sale->cai)
        ->and($note->status)->toBe(SaleStatus::Issued);
});

/*
|--------------------------------------------------------------------------
| Contabilidad
|--------------------------------------------------------------------------
*/

it('genera una partida cuadrada que no toca la cuenta de ingresos', function () {
    [$sale] = creditSaleWithStock($this);

    $note = $this->service->issue($this->service->saveDraft($sale, [
        'reason' => CreditNoteReason::Return,
        'description' => 'Devolución completa',
    ], [
        ['sale_item_id' => $sale->items->first()->id, 'quantity' => '10'],
    ]));

    $entry = $note->journalEntry();

    expect($entry)->not->toBeNull()
        ->and($entry->isBalanced())->toBeTrue()
        ->and($entry->status)->toBe(JournalEntryStatus::Posted)
        ->and($entry->source_type)->toBe('credit_note');

    $cuentas = $entry->load('lines.account')->lines->map(fn ($l) => $l->account->code)->all();

    // Se carga «Devoluciones sobre Ventas» (4.1.03), no la cuenta de ingresos
    // (4.1.01): el estado de resultados tiene que mostrar lo que se vendió y lo
    // que se devolvió por separado.
    expect($cuentas)->toContain('4.1.03')
        ->and($cuentas)->not->toContain('4.1.01');
});

it('devuelve el mismo costo con el que la mercadería salió', function () {
    [$sale, $product] = creditSaleWithStock($this);

    $costoVendido = $sale->items->first()->costAmount();

    $note = $this->service->issue($this->service->saveDraft($sale, [
        'reason' => CreditNoteReason::Return,
        'description' => 'Devolución completa',
    ], [
        ['sale_item_id' => $sale->items->first()->id, 'quantity' => '10'],
    ]));

    $movimiento = InventoryMovement::query()
        ->where('type', MovementType::SaleReturn)
        ->firstOrFail();

    // Vuelve por el importe exacto que salió, no por el promedio de hoy.
    expect($movimiento->valueAmount()->absolute()->toString())
        ->toBe($costoVendido->toString())
        ->and($note->items->first()->costAmount()->toString())
        ->toBe($costoVendido->toString());
});

it('prorratea el costo cuando vuelve solo una parte', function () {
    [$sale] = creditSaleWithStock($this, '10');

    $costoTotal = $sale->items->first()->costAmount();

    $note = $this->service->issue($this->service->saveDraft($sale, [
        'reason' => CreditNoteReason::Return,
        'description' => 'Devolución parcial',
    ], [
        ['sale_item_id' => $sale->items->first()->id, 'quantity' => '4'],
    ]));

    $esperado = Money::ofRounded(bcdiv(bcmul($costoTotal->toString(), '4', 8), '10', 8));

    expect($note->items->first()->costAmount()->toString())->toBe($esperado->toString());
});

/*
|--------------------------------------------------------------------------
| Cuenta por cobrar
|--------------------------------------------------------------------------
*/

it('rebaja el saldo sin contarlo como cobrado', function () {
    [$sale] = creditSaleWithStock($this);

    $receivable = $sale->receivable;
    $original = $receivable->originalAmount();

    $note = $this->service->issue($this->service->saveDraft($sale, [
        'reason' => CreditNoteReason::Return,
        'description' => 'Devolución parcial',
    ], [
        ['sale_item_id' => $sale->items->first()->id, 'quantity' => '3'],
    ]));

    $receivable->refresh();

    // El saldo baja, pero `paid_amount` sigue en cero: no entró dinero.
    expect($receivable->creditedAmount()->toString())->toBe($note->totalAmount()->toString())
        ->and($receivable->paidAmount()->isZero())->toBeTrue()
        ->and($receivable->balanceAmount()->toString())
        ->toBe($original->minus($note->totalAmount())->toString());
});

it('da por saldada la factura acreditada por completo', function () {
    [$sale] = creditSaleWithStock($this);

    $this->service->issue($this->service->saveDraft($sale, [
        'reason' => CreditNoteReason::Return,
        'description' => 'Devolución total',
    ], [
        ['sale_item_id' => $sale->items->first()->id, 'quantity' => '10'],
    ]));

    $receivable = $sale->receivable->refresh();

    expect($receivable->status)->toBe(ReceivableStatus::Paid)
        ->and($receivable->balanceAmount()->isZero())->toBeTrue()
        ->and($receivable->paidAmount()->isZero())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Guardas
|--------------------------------------------------------------------------
*/

it('no acredita más de lo que dice la factura', function () {
    [$sale] = creditSaleWithStock($this);

    $this->service->issue($this->service->saveDraft($sale, [
        'reason' => CreditNoteReason::Discount,
        'description' => 'Rebaja acordada',
    ], [
        ['sale_item_id' => $sale->items->first()->id, 'quantity' => '10'],
    ]));

    // Una segunda nota por lo mismo sobrepasaría la factura.
    $this->service->issue($this->service->saveDraft($sale, [
        'reason' => CreditNoteReason::Discount,
        'description' => 'Otra rebaja',
    ], [
        ['sale_item_id' => $sale->items->first()->id, 'quantity' => '1', 'unit_price' => '100.00'],
    ]));
})->throws(SalesException::class);

it('no devuelve más unidades de las que se vendieron', function () {
    [$sale] = creditSaleWithStock($this, '5');

    $this->service->saveDraft($sale, [
        'reason' => CreditNoteReason::Return,
        'description' => 'Devolución imposible',
    ], [
        ['sale_item_id' => $sale->items->first()->id, 'quantity' => '6'],
    ]);
})->throws(SalesException::class, 'la factura solo vendió');

it('no acredita sobre una factura anulada', function () {
    [$sale] = creditSaleWithStock($this);

    $this->sales->void($sale, 'Error de captura');

    $this->service->saveDraft($sale->refresh(), [
        'reason' => CreditNoteReason::Return,
        'description' => 'Sobre una factura muerta',
    ], [
        ['sale_item_id' => $sale->items->first()->id, 'quantity' => '1'],
    ]);
})->throws(SalesException::class, 'Solo se acredita sobre una factura emitida');

/*
|--------------------------------------------------------------------------
| Motivos
|--------------------------------------------------------------------------
*/

it('no mueve inventario cuando el motivo es un descuento', function () {
    [$sale, $product] = creditSaleWithStock($this);

    $antes = InventoryStock::query()->where('product_id', $product->id)->firstOrFail()->quantity;

    $this->service->issue($this->service->saveDraft($sale, [
        'reason' => CreditNoteReason::Discount,
        'description' => 'Rebaja por volumen acordada después de facturar',
    ], [
        ['sale_item_id' => $sale->items->first()->id, 'quantity' => '2'],
    ]));

    $despues = InventoryStock::query()->where('product_id', $product->id)->firstOrFail()->quantity;

    expect($despues)->toBe($antes)
        ->and(InventoryMovement::query()->where('type', MovementType::SaleReturn)->exists())
        ->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Anulación
|--------------------------------------------------------------------------
*/

it('al anular la nota devuelve el saldo y saca la mercadería otra vez', function () {
    [$sale, $product] = creditSaleWithStock($this);

    $note = $this->service->issue($this->service->saveDraft($sale, [
        'reason' => CreditNoteReason::Return,
        'description' => 'Devolución que luego se revierte',
    ], [
        ['sale_item_id' => $sale->items->first()->id, 'quantity' => '4'],
    ]));

    $conNota = InventoryStock::query()->where('product_id', $product->id)->firstOrFail()->quantity;

    $this->service->void($note, 'La devolución no procedía');

    $receivable = $sale->receivable->refresh();
    $final = InventoryStock::query()->where('product_id', $product->id)->firstOrFail()->quantity;

    expect($note->refresh()->status)->toBe(SaleStatus::Voided)
        ->and($receivable->creditedAmount()->isZero())->toBeTrue()
        ->and($receivable->balanceAmount()->toString())->toBe($sale->totalAmount()->toString())
        ->and((float) $final)->toBe((float) $conNota - 4.0)
        ->and($note->journalEntry())->toBeNull();
});

it('no consume el correlativo fiscal al anular', function () {
    [$sale] = creditSaleWithStock($this);

    $note = $this->service->issue($this->service->saveDraft($sale, [
        'reason' => CreditNoteReason::Return,
        'description' => 'Se anula después',
    ], [
        ['sale_item_id' => $sale->items->first()->id, 'quantity' => '1'],
    ]));

    $this->service->void($note, 'Error');

    // El número se conserva en el documento anulado: el correlativo se gastó y
    // el SAR espera poder ver qué pasó con él.
    expect($note->refresh()->number)->toBe('000-001-03-00000001')
        ->and(CreditNote::query()->count())->toBe(1);
});
