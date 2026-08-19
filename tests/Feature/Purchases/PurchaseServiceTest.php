<?php

declare(strict_types=1);

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Payables\Enums\PayableStatus;
use App\Domains\Payables\Models\Payable;
use App\Domains\Payables\Services\PayableService;
use App\Domains\Purchases\Enums\PurchaseStatus;
use App\Domains\Purchases\Exceptions\PurchaseException;
use App\Domains\Purchases\Models\Purchase;
use App\Domains\Purchases\Services\PurchaseService;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Support\Money;
use Carbon\CarbonImmutable;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->service = app(PurchaseService::class);
    $this->branch = mainBranch();
    $this->bank = account('1.1.02.01');
    $this->supplier = makeSupplier();
});

/**
 * Cabecera de una compra al crédito.
 *
 * @return array<string, mixed>
 */
function creditPurchaseData(int $supplierId, int $branchId, string $invoice, string $date = 'today'): array
{
    return [
        'branch_id' => $branchId,
        'warehouse_id' => warehouse()->id,
        'supplier_id' => $supplierId,
        'supplier_invoice_number' => $invoice,
        'date' => CarbonImmutable::parse($date)->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ];
}

/*
|--------------------------------------------------------------------------
| Cálculo y contabilización
|--------------------------------------------------------------------------
*/

it('calcula subtotal, impuesto acreditable y total', function () {
    $producto = makeProduct('0');

    $compra = $this->service->createAndReceive(
        creditPurchaseData($this->supplier->id, $this->branch->id, 'FAC-1001'),
        [['product_id' => $producto->id, 'quantity' => '10', 'unit_price' => '800.00']],
    );

    expect($compra->subtotalAmount()->toString())->toBe('8000.0000')
        ->and($compra->taxAmount()->toString())->toBe('1200.0000')
        ->and($compra->totalAmount()->toString())->toBe('9200.0000');
});

it('genera la partida de compra cuadrada', function () {
    $producto = makeProduct('0');
    $producto->forceFill(['track_inventory' => true])->save();

    $compra = $this->service->createAndReceive(
        creditPurchaseData($this->supplier->id, $this->branch->id, 'FAC-1002'),
        [['product_id' => $producto->id, 'quantity' => '10', 'unit_price' => '800.00']],
    );

    $entry = $compra->journalEntry();
    $lines = $entry->lines->keyBy('account_id');

    expect($entry->isBalanced())->toBeTrue()
        ->and($entry->status)->toBe(JournalEntryStatus::Posted)
        // Inventario al debe, crédito fiscal al debe, proveedores al haber.
        ->and($lines[account('1.1.04.01')->id]->debitAmount()->toString())->toBe('8000.0000')
        ->and($lines[account('1.1.05.01')->id]->debitAmount()->toString())->toBe('1200.0000')
        ->and($lines[account('2.1.01.01')->id]->creditAmount()->toString())->toBe('9200.0000');
});

it('usa el impuesto acreditable y no el impuesto por pagar', function () {
    $producto = makeProduct('0');

    $compra = $this->service->createAndReceive(
        creditPurchaseData($this->supplier->id, $this->branch->id, 'FAC-1003'),
        [['product_id' => $producto->id, 'quantity' => '1', 'unit_price' => '1000.00']],
    );

    $cuentas = $compra->journalEntry()->lines->pluck('account_id');

    // En una compra el impuesto es un derecho a favor, no una deuda.
    expect($cuentas)->toContain(account('1.1.05.01')->id)
        ->and($cuentas)->not->toContain(account('2.1.02.01')->id);
});

it('lleva a gasto lo que no es inventario', function () {
    $servicio = makeProduct('0');
    $servicio->forceFill(['type' => 'service', 'track_inventory' => false])->save();

    $compra = $this->service->createAndReceive(
        creditPurchaseData($this->supplier->id, $this->branch->id, 'FAC-1004'),
        [['product_id' => $servicio->id, 'quantity' => '1', 'unit_price' => '5000.00']],
    );

    $cuentas = $compra->journalEntry()->lines->pluck('account_id');

    expect($cuentas)->toContain(account('6.1.12')->id)
        ->and($cuentas)->not->toContain(account('1.1.04.01')->id);
});

it('respeta la cuenta de gasto indicada en la línea', function () {
    $alquiler = account('6.1.03');

    $compra = $this->service->createAndReceive(
        creditPurchaseData($this->supplier->id, $this->branch->id, 'FAC-1005'),
        [[
            'description' => 'Alquiler de bodega',
            'quantity' => '1',
            'unit_price' => '12000.00',
            'tax_id' => tax()->id,
            'expense_account_id' => $alquiler->id,
        ]],
    );

    expect($compra->journalEntry()->lines->pluck('account_id'))->toContain($alquiler->id);
});

it('registra el costo neto de descuentos', function () {
    $producto = makeProduct('0');
    $producto->forceFill(['track_inventory' => true])->save();

    $compra = $this->service->createAndReceive(
        creditPurchaseData($this->supplier->id, $this->branch->id, 'FAC-1006'),
        [[
            'product_id' => $producto->id,
            'quantity' => '10',
            'unit_price' => '1000.00',
            'discount_rate' => '20',
        ]],
    );

    $lines = $compra->journalEntry()->lines->keyBy('account_id');

    // 10.000 − 20 % = 8.000 al inventario. El descuento no va a una cuenta
    // aparte: el inventario debe quedar a lo que realmente costó, porque de ahí
    // sale el costo promedio.
    expect($compra->discountAmount()->toString())->toBe('2000.0000')
        ->and($lines[account('1.1.04.01')->id]->debitAmount()->toString())->toBe('8000.0000')
        ->and($compra->items->first()->netUnitCost()->toString())->toBe('800.0000');
});

it('paga de contado sin abrir cuenta por pagar', function () {
    $producto = makeProduct('0');

    $compra = $this->service->createAndReceive([
        'branch_id' => $this->branch->id,
        'supplier_id' => $this->supplier->id,
        'supplier_invoice_number' => 'FAC-1007',
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Cash,
        'payment_account_id' => $this->bank->id,
    ], [['product_id' => $producto->id, 'quantity' => '1', 'unit_price' => '1000.00']]);

    $lines = $compra->journalEntry()->lines->keyBy('account_id');

    expect($compra->payable)->toBeNull()
        ->and($lines[$this->bank->id]->creditAmount()->toString())->toBe('1150.0000');
});

it('abre la cuenta por pagar con la fecha de vencimiento del crédito', function () {
    $producto = makeProduct('0');

    $compra = $this->service->createAndReceive(
        creditPurchaseData($this->supplier->id, $this->branch->id, 'FAC-1008', '2026-03-10'),
        [['product_id' => $producto->id, 'quantity' => '1', 'unit_price' => '1000.00']],
    );

    expect($compra->payable)->not->toBeNull()
        ->and($compra->payable->balanceAmount()->toString())->toBe('1150.0000')
        ->and($compra->payable->due_date->format('Y-m-d'))->toBe('2026-04-09')
        ->and($compra->payable->document_number)->toBe('FAC-1008');
});

/*
|--------------------------------------------------------------------------
| Factura duplicada
|--------------------------------------------------------------------------
*/

it('impide registrar dos veces la misma factura del proveedor', function () {
    $producto = makeProduct('0');
    $lineas = [['product_id' => $producto->id, 'quantity' => '1', 'unit_price' => '100.00']];

    $this->service->createAndReceive(
        creditPurchaseData($this->supplier->id, $this->branch->id, 'FAC-2001'),
        $lineas,
    );

    expect(fn () => $this->service->createAndReceive(
        creditPurchaseData($this->supplier->id, $this->branch->id, 'FAC-2001'),
        $lineas,
    ))->toThrow(PurchaseException::class, 'ya está registrada');

    expect(Purchase::query()->count())->toBe(1);
});

it('permite el mismo número de factura de proveedores distintos', function () {
    $otro = makeSupplier();
    $producto = makeProduct('0');
    $lineas = [['product_id' => $producto->id, 'quantity' => '1', 'unit_price' => '100.00']];

    $this->service->createAndReceive(
        creditPurchaseData($this->supplier->id, $this->branch->id, 'FAC-3001'),
        $lineas,
    );
    $this->service->createAndReceive(
        creditPurchaseData($otro->id, $this->branch->id, 'FAC-3001'),
        $lineas,
    );

    expect(Purchase::query()->count())->toBe(2);
});

it('permite volver a registrar la factura tras anular la compra', function () {
    $producto = makeProduct('0');
    $lineas = [['product_id' => $producto->id, 'quantity' => '1', 'unit_price' => '100.00']];

    $compra = $this->service->createAndReceive(
        creditPurchaseData($this->supplier->id, $this->branch->id, 'FAC-4001'),
        $lineas,
    );
    $this->service->void($compra, 'Datos equivocados en la captura');

    $nueva = $this->service->createAndReceive(
        creditPurchaseData($this->supplier->id, $this->branch->id, 'FAC-4001'),
        $lineas,
    );

    expect($nueva->isReceived())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Validaciones y anulación
|--------------------------------------------------------------------------
*/

it('exige número de factura del proveedor', function () {
    $borrador = $this->service->saveDraft([
        'branch_id' => $this->branch->id,
        'supplier_id' => $this->supplier->id,
        'supplier_invoice_number' => '   ',
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['description' => 'Algo', 'quantity' => '1', 'unit_price' => '100.00']]);

    expect(fn () => $this->service->receive($borrador))
        ->toThrow(PurchaseException::class, 'número de la factura');
});

it('rechaza comprar a un proveedor inactivo', function () {
    $inactivo = makeSupplier(['is_active' => false]);

    expect(fn () => $this->service->createAndReceive(
        creditPurchaseData($inactivo->id, $this->branch->id, 'FAC-5001'),
        [['description' => 'Algo', 'quantity' => '1', 'unit_price' => '100.00']],
    ))->toThrow(PurchaseException::class, 'está inactivo');
});

it('exige cuenta de pago en la compra de contado', function () {
    expect(fn () => $this->service->createAndReceive([
        'branch_id' => $this->branch->id,
        'supplier_id' => $this->supplier->id,
        'supplier_invoice_number' => 'FAC-5002',
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Cash,
    ], [['description' => 'Algo', 'quantity' => '1', 'unit_price' => '100.00']]))
        ->toThrow(PurchaseException::class, 'caja o banco');
});

it('anula la compra, revierte la partida y cancela la cuenta por pagar', function () {
    $producto = makeProduct('0');

    $compra = $this->service->createAndReceive(
        creditPurchaseData($this->supplier->id, $this->branch->id, 'FAC-6001'),
        [['product_id' => $producto->id, 'quantity' => '1', 'unit_price' => '1000.00']],
    );

    $numero = $compra->number;
    $anulada = $this->service->void($compra, 'Mercadería devuelta al proveedor');

    expect($anulada->status)->toBe(PurchaseStatus::Voided)
        ->and($anulada->number)->toBe($numero)
        ->and($anulada->items()->count())->toBe(1)
        ->and($anulada->payable->refresh()->status)->toBe(PayableStatus::Voided);

    $entry = acrossCompanies(fn () => JournalEntry::acrossCompanies()
        ->where('source_type', 'purchase')->where('source_id', $compra->id)->firstOrFail());

    expect($entry->status)->toBe(JournalEntryStatus::Voided);
});

it('no anula una compra con pagos aplicados', function () {
    $producto = makeProduct('0');

    $compra = $this->service->createAndReceive(
        creditPurchaseData($this->supplier->id, $this->branch->id, 'FAC-6002'),
        [['product_id' => $producto->id, 'quantity' => '1', 'unit_price' => '1000.00']],
    );

    app(PayableService::class)->applyPayment($compra->payable, Money::of('500.00'));

    expect(fn () => $this->service->void($compra->refresh(), 'Intento tardío'))
        ->toThrow(PurchaseException::class, 'pagos aplicados');
});

it('exige motivo para anular', function () {
    $compra = $this->service->createAndReceive(
        creditPurchaseData($this->supplier->id, $this->branch->id, 'FAC-6003'),
        [['description' => 'Algo', 'quantity' => '1', 'unit_price' => '100.00', 'tax_id' => tax()->id]],
    );

    expect(fn () => $this->service->void($compra, ' '))
        ->toThrow(PurchaseException::class, 'motivo');
});

it('guarda un borrador sin numerar ni contabilizar', function () {
    $compra = $this->service->saveDraft(
        creditPurchaseData($this->supplier->id, $this->branch->id, 'FAC-7001'),
        [['description' => 'Algo', 'quantity' => '2', 'unit_price' => '100.00', 'tax_id' => tax()->id]],
    );

    expect($compra->status)->toBe(PurchaseStatus::Draft)
        ->and($compra->number)->toBeNull()
        ->and($compra->totalAmount()->toString())->toBe('230.0000')
        ->and($compra->journalEntry())->toBeNull()
        ->and(Payable::query()->count())->toBe(0);
});

it('aísla las compras entre empresas', function () {
    $this->service->createAndReceive(
        creditPurchaseData($this->supplier->id, $this->branch->id, 'FAC-8001'),
        [['description' => 'Algo', 'quantity' => '1', 'unit_price' => '100.00', 'tax_id' => tax()->id]],
    );

    $otra = accountingCompany();
    actingAsUserOf($otra, role: PermissionCatalog::ACCOUNTANT);

    expect(Purchase::query()->count())->toBe(0)
        ->and(acrossCompanies(fn () => Purchase::acrossCompanies()->count()))->toBe(1);
});
