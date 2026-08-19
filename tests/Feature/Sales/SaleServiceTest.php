<?php

declare(strict_types=1);

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Fiscal\Models\FiscalAuthorization;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Receivables\Enums\ReceivableStatus;
use App\Domains\Receivables\Models\Receivable;
use App\Domains\Receivables\Services\ReceivableService;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleService;
use App\Support\Money;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->service = app(SaleService::class);
    $this->branch = mainBranch();
    $this->cashAccount = account('1.1.02.01');
});

/**
 * Datos de cabecera de una venta de contado.
 *
 * @return array<string, mixed>
 */
function cashSaleData(int $customerId, int $branchId, int $accountId): array
{
    return [
        'branch_id' => $branchId,
        'customer_id' => $customerId,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Cash,
        'deposit_account_id' => $accountId,
    ];
}

/*
|--------------------------------------------------------------------------
| Cálculo de la factura
|--------------------------------------------------------------------------
*/

it('calcula subtotal, impuesto y total', function () {
    $customer = makeCustomer();
    $product = makeProduct('1000.00');

    $sale = $this->service->createAndIssue(
        cashSaleData($customer->id, $this->branch->id, $this->cashAccount->id),
        [['product_id' => $product->id, 'quantity' => '2', 'unit_price' => '1000.00']],
    );

    expect($sale->subtotalAmount()->toString())->toBe('2000.0000')
        ->and($sale->taxAmount()->toString())->toBe('300.0000')   // 15 %
        ->and($sale->totalAmount()->toString())->toBe('2300.0000');
});

it('aplica el descuento antes del impuesto', function () {
    $customer = makeCustomer();
    $product = makeProduct('1000.00');

    $sale = $this->service->createAndIssue(
        cashSaleData($customer->id, $this->branch->id, $this->cashAccount->id),
        [[
            'product_id' => $product->id,
            'quantity' => '1',
            'unit_price' => '1000.00',
            'discount_rate' => '10',
        ]],
    );

    // 1000 − 10 % = 900; ISV 15 % sobre 900 = 135.
    expect($sale->discountAmount()->toString())->toBe('100.0000')
        ->and($sale->subtotalAmount()->toString())->toBe('900.0000')
        ->and($sale->taxAmount()->toString())->toBe('135.0000')
        ->and($sale->totalAmount()->toString())->toBe('1035.0000');
});

it('despeja la base cuando el impuesto va incluido en el precio', function () {
    $included = tax('ISV15');
    $included->forceFill(['is_included' => true])->save();

    $customer = makeCustomer();
    $product = makeProduct('115.00', $included);

    $sale = $this->service->createAndIssue(
        cashSaleData($customer->id, $this->branch->id, $this->cashAccount->id),
        [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '115.00']],
    );

    // El cliente paga 115: base 100 más 15 de ISV.
    expect($sale->subtotalAmount()->toString())->toBe('100.0000')
        ->and($sale->taxAmount()->toString())->toBe('15.0000')
        ->and($sale->totalAmount()->toString())->toBe('115.0000');
});

it('no cobra impuesto con un producto exento', function () {
    $customer = makeCustomer();
    $product = makeProduct('500.00', tax('EXE'));

    $sale = $this->service->createAndIssue(
        cashSaleData($customer->id, $this->branch->id, $this->cashAccount->id),
        [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '500.00']],
    );

    expect($sale->taxAmount()->isZero())->toBeTrue()
        ->and($sale->totalAmount()->toString())->toBe('500.0000');
});

it('suma los impuestos línea por línea sin desviarse', function () {
    $customer = makeCustomer();
    $product = makeProduct('33.33');

    $lines = array_fill(0, 7, [
        'product_id' => $product->id,
        'quantity' => '1',
        'unit_price' => '33.33',
    ]);

    $sale = $this->service->createAndIssue(
        cashSaleData($customer->id, $this->branch->id, $this->cashAccount->id),
        $lines,
    );

    // ISV por línea: 33.33 × 15 % = 5.00 (redondeado). Siete líneas: 35.00.
    // Sobre el total (233.31) daría 34.9965 → 35.00; deben coincidir.
    // La suma de las líneas se hace con Money: sumarlas con floats da
    // 268.30999999999995, que es exactamente el error que este sistema evita.
    $sumaDeLineas = Money::sum($sale->items->map(fn ($i) => $i->totalAmount())->all());

    expect($sale->subtotalAmount()->toString())->toBe('233.3100')
        ->and($sale->taxAmount()->toString())->toBe('35.0000')
        ->and($sale->totalAmount()->toString())->toBe('268.3100')
        ->and($sumaDeLineas->equals($sale->totalAmount()))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Emisión
|--------------------------------------------------------------------------
*/

it('numera la factura con el correlativo fiscal y congela el CAI', function () {
    $customer = makeCustomer();
    $product = makeProduct();

    $sale = $this->service->createAndIssue(
        cashSaleData($customer->id, $this->branch->id, $this->cashAccount->id),
        [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '100.00']],
    );

    $authorization = FiscalAuthorization::query()->firstOrFail();

    // `EEE-PPP-TT-NNNNNNNN`, el formato del régimen de facturación.
    expect($sale->number)->toBe('000-001-01-00000001')
        ->and($sale->status)->toBe(SaleStatus::Issued)
        ->and($sale->issued_by)->toBe($this->user->id)
        // Los datos fiscales quedan copiados en el documento, no leídos de la
        // autorización: una reimpresión tiene que dar el mismo papel.
        ->and($sale->cai)->toBe($authorization->cai)
        ->and($sale->fiscal_sequence)->toBe(1)
        ->and($sale->fiscal_range_from)->toBe($authorization->range_from)
        ->and($sale->fiscal_range_to)->toBe($authorization->range_to)
        ->and($sale->fiscal_limit_date->toDateString())
        ->toBe($authorization->limit_date->toDateString());
});

it('genera una partida contable cuadrada', function () {
    $customer = makeCustomer();
    $product = makeProduct('1000.00');

    $sale = $this->service->createAndIssue(
        cashSaleData($customer->id, $this->branch->id, $this->cashAccount->id),
        [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '1000.00']],
    );

    $entry = $sale->journalEntry();

    expect($entry)->not->toBeNull()
        ->and($entry->isBalanced())->toBeTrue()
        ->and($entry->status)->toBe(JournalEntryStatus::Posted)
        ->and($entry->source_type)->toBe('sale')
        ->and($entry->source_id)->toBe($sale->id)
        ->and($entry->totalDebit()->toString())->toBe('1150.0000');
});

it('carga a caja en la venta de contado y a clientes en la de crédito', function () {
    $product = makeProduct('1000.00');

    $contado = $this->service->createAndIssue(
        cashSaleData(makeCustomer()->id, $this->branch->id, $this->cashAccount->id),
        [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '1000.00']],
    );

    $credito = $this->service->createAndIssue([
        'branch_id' => $this->branch->id,
        'customer_id' => makeCustomer(['credit_limit' => '50000.00', 'credit_days' => 30])->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '1000.00']]);

    $cuentasContado = $contado->journalEntry()->lines->pluck('account_id');
    $cuentasCredito = $credito->journalEntry()->lines->pluck('account_id');

    expect($cuentasContado)->toContain($this->cashAccount->id)
        ->and($cuentasCredito)->toContain(account('1.1.03.01')->id)
        ->and($cuentasCredito)->not->toContain($this->cashAccount->id);
});

it('registra el ingreso bruto y el descuento por separado', function () {
    $customer = makeCustomer();
    $product = makeProduct('1000.00');

    $sale = $this->service->createAndIssue(
        cashSaleData($customer->id, $this->branch->id, $this->cashAccount->id),
        [[
            'product_id' => $product->id,
            'quantity' => '1',
            'unit_price' => '1000.00',
            'discount_rate' => '10',
        ]],
    );

    $lines = $sale->journalEntry()->lines->keyBy('account_id');

    // La cuenta de ventas se acredita por los 1000 brutos y el descuento se
    // carga aparte, para que el estado de resultados los muestre separados.
    expect($lines[account('4.1.01')->id]->creditAmount()->toString())->toBe('1000.0000')
        ->and($lines[account('4.1.04')->id]->debitAmount()->toString())->toBe('100.0000');
});

it('abre la cuenta por cobrar solo en la venta al crédito', function () {
    $product = makeProduct('1000.00');

    $contado = $this->service->createAndIssue(
        cashSaleData(makeCustomer()->id, $this->branch->id, $this->cashAccount->id),
        [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '1000.00']],
    );

    $credito = $this->service->createAndIssue([
        'branch_id' => $this->branch->id,
        'customer_id' => makeCustomer(['credit_limit' => '50000.00', 'credit_days' => 15])->id,
        'date' => '2026-03-10',
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 15,
    ], [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '1000.00']]);

    expect($contado->receivable)->toBeNull()
        ->and($credito->receivable)->not->toBeNull()
        ->and($credito->receivable->balanceAmount()->toString())->toBe('1150.0000')
        ->and($credito->receivable->due_date->format('Y-m-d'))->toBe('2026-03-25');
});

it('congela la descripción del producto en la línea', function () {
    $customer = makeCustomer();
    $product = makeProduct('100.00');

    $sale = $this->service->createAndIssue(
        cashSaleData($customer->id, $this->branch->id, $this->cashAccount->id),
        [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '100.00']],
    );

    $original = $product->name;
    $product->forceFill(['name' => 'Nombre cambiado después'])->save();

    expect($sale->items->first()->description)->toBe($original);
});

it('permite facturar un concepto libre sin producto', function () {
    $customer = makeCustomer();

    $sale = $this->service->createAndIssue(
        cashSaleData($customer->id, $this->branch->id, $this->cashAccount->id),
        [[
            'description' => 'Servicio de instalación',
            'quantity' => '1',
            'unit_price' => '2500.00',
            'tax_id' => tax()->id,
        ]],
    );

    expect($sale->items->first()->product_id)->toBeNull()
        ->and($sale->items->first()->description)->toBe('Servicio de instalación')
        ->and($sale->totalAmount()->toString())->toBe('2875.0000');
});

/*
|--------------------------------------------------------------------------
| Límite de crédito
|--------------------------------------------------------------------------
*/

it('rechaza la venta al crédito a un cliente sin crédito autorizado', function () {
    $customer = makeCustomer();
    $product = makeProduct('100.00');

    expect(fn () => $this->service->createAndIssue([
        'branch_id' => $this->branch->id,
        'customer_id' => $customer->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '100.00']]))
        ->toThrow(SalesException::class, 'no tiene crédito autorizado');
});

it('rechaza la venta que excede el límite de crédito', function () {
    $customer = makeCustomer(['credit_limit' => '1000.00', 'credit_days' => 30]);
    $product = makeProduct('2000.00');

    expect(fn () => $this->service->createAndIssue([
        'branch_id' => $this->branch->id,
        'customer_id' => $customer->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '2000.00']]))
        ->toThrow(SalesException::class, 'excede el límite de crédito');
});

it('cuenta el saldo pendiente al validar el límite de crédito', function () {
    $customer = makeCustomer(['credit_limit' => '2000.00', 'credit_days' => 30]);
    $product = makeProduct('1500.00');

    $header = [
        'branch_id' => $this->branch->id,
        'customer_id' => $customer->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ];
    $lines = [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '1500.00']];

    // La primera cabe (1725 con impuesto); la segunda ya no.
    $this->service->createAndIssue($header, $lines);

    expect(fn () => $this->service->createAndIssue($header, $lines))
        ->toThrow(SalesException::class, 'excede el límite');
});

it('permite pasar por encima del límite con autorización', function () {
    $customer = makeCustomer(['credit_limit' => '100.00', 'credit_days' => 30]);
    $product = makeProduct('5000.00');

    $sale = $this->service->createAndIssue([
        'branch_id' => $this->branch->id,
        'customer_id' => $customer->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '5000.00']],
        overrideCreditLimit: true);

    expect($sale->isIssued())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Validaciones y anulación
|--------------------------------------------------------------------------
*/

it('rechaza emitir una factura sin líneas', function () {
    $sale = $this->service->saveDraft(
        cashSaleData(makeCustomer()->id, $this->branch->id, $this->cashAccount->id),
        [],
    );

    expect(fn () => $this->service->issue($sale))
        ->toThrow(SalesException::class, 'no tiene líneas');
});

it('rechaza facturar a un cliente inactivo', function () {
    $customer = makeCustomer(['is_active' => false]);
    $product = makeProduct();

    expect(fn () => $this->service->createAndIssue(
        cashSaleData($customer->id, $this->branch->id, $this->cashAccount->id),
        [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '100.00']],
    ))->toThrow(SalesException::class, 'está inactivo');
});

it('exige cuenta de depósito en la venta de contado', function () {
    $customer = makeCustomer();
    $product = makeProduct();

    expect(fn () => $this->service->createAndIssue([
        'branch_id' => $this->branch->id,
        'customer_id' => $customer->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Cash,
    ], [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '100.00']]))
        ->toThrow(SalesException::class, 'caja o banco');
});

it('anula la factura, revierte la partida y cancela la cuenta por cobrar', function () {
    $customer = makeCustomer(['credit_limit' => '50000.00', 'credit_days' => 30]);
    $product = makeProduct('1000.00');

    $sale = $this->service->createAndIssue([
        'branch_id' => $this->branch->id,
        'customer_id' => $customer->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '1000.00']]);

    $numero = $sale->number;
    $anulada = $this->service->void($sale, 'Error en el pedido del cliente');

    expect($anulada->status)->toBe(SaleStatus::Voided)
        ->and($anulada->number)->toBe($numero)
        ->and($anulada->void_reason)->toBe('Error en el pedido del cliente')
        ->and($anulada->items()->count())->toBe(1)
        ->and($anulada->receivable->refresh()->status)->toBe(ReceivableStatus::Voided);

    $entry = acrossCompanies(fn () => JournalEntry::acrossCompanies()
        ->where('source_type', 'sale')->where('source_id', $sale->id)->firstOrFail());

    expect($entry->status)->toBe(JournalEntryStatus::Voided);
});

it('exige motivo para anular', function () {
    $sale = $this->service->createAndIssue(
        cashSaleData(makeCustomer()->id, $this->branch->id, $this->cashAccount->id),
        [['product_id' => makeProduct()->id, 'quantity' => '1', 'unit_price' => '100.00']],
    );

    expect(fn () => $this->service->void($sale, '  '))
        ->toThrow(SalesException::class, 'motivo');
});

it('no anula una factura con abonos aplicados', function () {
    $customer = makeCustomer(['credit_limit' => '50000.00', 'credit_days' => 30]);
    $product = makeProduct('1000.00');

    $sale = $this->service->createAndIssue([
        'branch_id' => $this->branch->id,
        'customer_id' => $customer->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '1000.00']]);

    app(ReceivableService::class)
        ->applyPayment($sale->receivable, Money::of('500.00'));

    expect(fn () => $this->service->void($sale->refresh(), 'Intento tardío'))
        ->toThrow(SalesException::class, 'abonos aplicados');
});

it('no permite editar una factura emitida', function () {
    $sale = $this->service->createAndIssue(
        cashSaleData(makeCustomer()->id, $this->branch->id, $this->cashAccount->id),
        [['product_id' => makeProduct()->id, 'quantity' => '1', 'unit_price' => '100.00']],
    );

    expect(fn () => $this->service->updateDraft($sale, [], []))
        ->toThrow(SalesException::class);
});

it('no contabiliza dos veces la misma factura', function () {
    $sale = $this->service->createAndIssue(
        cashSaleData(makeCustomer()->id, $this->branch->id, $this->cashAccount->id),
        [['product_id' => makeProduct()->id, 'quantity' => '1', 'unit_price' => '100.00']],
    );

    expect(fn () => $this->service->issue($sale->refresh()))
        ->toThrow(SalesException::class, 'ya fue emitida');

    expect(acrossCompanies(fn () => JournalEntry::acrossCompanies()
        ->where('source_type', 'sale')->where('source_id', $sale->id)->count()))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Borradores y aislamiento
|--------------------------------------------------------------------------
*/

it('guarda un borrador sin numerar ni contabilizar', function () {
    $sale = $this->service->saveDraft(
        cashSaleData(makeCustomer()->id, $this->branch->id, $this->cashAccount->id),
        [['product_id' => makeProduct()->id, 'quantity' => '2', 'unit_price' => '100.00']],
    );

    expect($sale->status)->toBe(SaleStatus::Draft)
        ->and($sale->number)->toBeNull()
        ->and($sale->totalAmount()->toString())->toBe('230.0000')
        ->and($sale->journalEntry())->toBeNull()
        ->and(Receivable::query()->count())->toBe(0);
});

it('aísla las facturas entre empresas', function () {
    $this->service->createAndIssue(
        cashSaleData(makeCustomer()->id, $this->branch->id, $this->cashAccount->id),
        [['product_id' => makeProduct()->id, 'quantity' => '1', 'unit_price' => '100.00']],
    );

    $otra = accountingCompany();
    actingAsUserOf($otra, role: PermissionCatalog::ACCOUNTANT);

    expect(Sale::query()->count())->toBe(0)
        ->and(acrossCompanies(fn () => Sale::acrossCompanies()->count()))->toBe(1);
});
