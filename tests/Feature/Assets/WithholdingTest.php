<?php

declare(strict_types=1);

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Assets\Enums\WithholdingScope;
use App\Domains\Assets\Models\Withholding;
use App\Domains\Assets\Models\WithholdingType;
use App\Domains\Payables\Services\PaymentService;
use App\Domains\Purchases\Services\PurchaseService;
use App\Domains\Receivables\Services\ReceiptService;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Services\SaleService;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();

    $this->purchases = app(PurchaseService::class);
    $this->payments = app(PaymentService::class);
    $this->sales = app(SaleService::class);
    $this->receipts = app(ReceiptService::class);

    $this->branch = mainBranch();
    $this->warehouse = warehouse();
    $this->bank = account('1.1.02.01');
    $this->supplier = makeSupplier();
    $this->customer = makeCustomer(['credit_limit' => '900000.00', 'credit_days' => 30]);
    $this->product = makeProduct('1000.00', tracked: true);
});

/**
 * Tipo de retención configurado.
 */
function withholdingType(
    string $code = 'ISR125',
    string $rate = '12.5',
    WithholdingScope $scope = WithholdingScope::Purchase,
    ?string $accountCode = null,
): WithholdingType {
    $accountCode ??= $scope === WithholdingScope::Purchase ? '2.1.02.03' : '1.1.05.02';

    $type = new WithholdingType;
    $type->forceFill([
        'company_id' => app(CompanyContext::class)->idOrFail(),
        'code' => $code,
        'name' => 'Retención de ISR',
        'kind' => 'income_tax',
        'base' => 'total',
        'rate' => $rate,
        'applies_to' => $scope,
        'account_id' => account($accountCode)->id,
        'is_active' => true,
    ])->save();

    return $type;
}

/**
 * Compra al crédito de la que después se paga.
 */
function creditPurchaseFor(object $ctx, string $unitPrice, string $invoice)
{
    return $ctx->purchases->createAndReceive([
        'branch_id' => $ctx->branch->id,
        'warehouse_id' => $ctx->warehouse->id,
        'supplier_id' => $ctx->supplier->id,
        'supplier_invoice_number' => $invoice,
        'date' => '2026-03-01',
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $ctx->product->id, 'quantity' => '10', 'unit_price' => $unitPrice]]);
}

/*
|--------------------------------------------------------------------------
| Retención al proveedor
|--------------------------------------------------------------------------
*/

it('retiene al proveedor y saca del banco solo el neto', function () {
    $tipo = withholdingType(rate: '12.5');
    $compra = creditPurchaseFor($this, '1000.00', 'FAC-R-01');

    $pago = $this->payments->create([
        'branch_id' => $this->branch->id,
        'supplier_id' => $this->supplier->id,
        'date' => '2026-03-15',
        'payment_method' => PaymentMethod::Transfer,
        'payment_account_id' => $this->bank->id,
        'withholdings' => [['withholding_type_id' => $tipo->id]],
    ], [['payable_id' => $compra->payable->id, 'amount' => '10000.00']]);

    $lines = $pago->journalEntry()->lines->keyBy('account_id');

    expect($lines[account('2.1.01.01')->id]->debitAmount()->toString())->toBe('10000.0000')
        // Retenido: 12.5 % de 10 000.
        ->and($lines[account('2.1.02.03')->id]->creditAmount()->toString())->toBe('1250.0000')
        // Del banco solo sale el neto.
        ->and($lines[$this->bank->id]->creditAmount()->toString())->toBe('8750.0000')
        ->and($pago->journalEntry()->isBalanced())->toBeTrue();
});

it('deja la cuenta del proveedor saldada por el total de su factura', function () {
    $tipo = withholdingType();
    $compra = creditPurchaseFor($this, '1000.00', 'FAC-R-02');

    $this->payments->create([
        'branch_id' => $this->branch->id,
        'supplier_id' => $this->supplier->id,
        'date' => '2026-03-15',
        'payment_method' => PaymentMethod::Transfer,
        'payment_account_id' => $this->bank->id,
        'withholdings' => [['withholding_type_id' => $tipo->id]],
    ], [['payable_id' => $compra->payable->id, 'amount' => '11500.00']]);

    // El proveedor queda saldado aunque solo se le transfirió el neto: la
    // retención también cancela deuda.
    expect($compra->payable->refresh()->balanceAmount()->isZero())->toBeTrue();
});

it('guarda el rastro de la retención con la tasa del momento', function () {
    $tipo = withholdingType(rate: '12.5');
    $compra = creditPurchaseFor($this, '1000.00', 'FAC-R-03');

    $this->payments->create([
        'branch_id' => $this->branch->id,
        'supplier_id' => $this->supplier->id,
        'date' => '2026-03-15',
        'payment_method' => PaymentMethod::Transfer,
        'payment_account_id' => $this->bank->id,
        'withholdings' => [['withholding_type_id' => $tipo->id]],
    ], [['payable_id' => $compra->payable->id, 'amount' => '10000.00']]);

    $retencion = Withholding::query()->sole();

    // La tasa cambia después: el documento sigue mostrando la de entonces.
    $tipo->forceFill(['rate' => '10'])->save();

    expect($retencion->rate)->toBe('12.500000')
        ->and($retencion->amountMoney()->toString())->toBe('1250.0000')
        ->and($retencion->baseAmount()->toString())->toBe('10000.0000');
});

it('acepta varias retenciones en el mismo pago', function () {
    $isr = withholdingType(code: 'ISR1', rate: '1');
    $isv = withholdingType(code: 'ISV', rate: '1.5');
    $compra = creditPurchaseFor($this, '1000.00', 'FAC-R-04');

    $pago = $this->payments->create([
        'branch_id' => $this->branch->id,
        'supplier_id' => $this->supplier->id,
        'date' => '2026-03-15',
        'payment_method' => PaymentMethod::Transfer,
        'payment_account_id' => $this->bank->id,
        'withholdings' => [
            ['withholding_type_id' => $isr->id],
            ['withholding_type_id' => $isv->id],
        ],
    ], [['payable_id' => $compra->payable->id, 'amount' => '10000.00']]);

    $lines = $pago->journalEntry()->lines->keyBy('account_id');

    // 1 % + 1.5 % = 250; sale 9 750.
    expect($lines[$this->bank->id]->creditAmount()->toString())->toBe('9750.0000')
        ->and(Withholding::query()->count())->toBe(2)
        ->and($pago->journalEntry()->isBalanced())->toBeTrue();
});

it('paga normal cuando no se declara ninguna retención', function () {
    $compra = creditPurchaseFor($this, '1000.00', 'FAC-R-05');

    $pago = $this->payments->create([
        'branch_id' => $this->branch->id,
        'supplier_id' => $this->supplier->id,
        'date' => '2026-03-15',
        'payment_method' => PaymentMethod::Transfer,
        'payment_account_id' => $this->bank->id,
    ], [['payable_id' => $compra->payable->id, 'amount' => '5000.00']]);

    $lines = $pago->journalEntry()->lines->keyBy('account_id');

    expect($lines[$this->bank->id]->creditAmount()->toString())->toBe('5000.0000')
        ->and(Withholding::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Retención que nos practica el cliente
|--------------------------------------------------------------------------
*/

it('registra como activo lo que el cliente nos retuvo', function () {
    $tipo = withholdingType(code: 'ISRV', rate: '12.5', scope: WithholdingScope::Sale);

    // Sin existencia no se puede vender: la Fase 5 lo bloquea.
    creditPurchaseFor($this, '600.00', 'FAC-STOCK');

    $venta = $this->sales->createAndIssue([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $this->warehouse->id,
        'customer_id' => $this->customer->id,
        'date' => '2026-03-05',
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $this->product->id, 'quantity' => '10', 'unit_price' => '1000.00']]);

    $recibo = $this->receipts->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => '2026-03-20',
        'payment_method' => PaymentMethod::Transfer,
        'deposit_account_id' => $this->bank->id,
        'withholdings' => [['withholding_type_id' => $tipo->id]],
    ], [['receivable_id' => $venta->receivable->id, 'amount' => '10000.00']]);

    $lines = $recibo->journalEntry()->lines->keyBy('account_id');

    expect($lines[account('1.1.03.01')->id]->creditAmount()->toString())->toBe('10000.0000')
        // Lo retenido es un impuesto pagado por anticipado, no un descuento.
        ->and($lines[account('1.1.05.02')->id]->debitAmount()->toString())->toBe('1250.0000')
        ->and($lines[$this->bank->id]->debitAmount()->toString())->toBe('8750.0000')
        ->and($recibo->journalEntry()->isBalanced())->toBeTrue();
});

it('cancela la cuenta del cliente por el total aunque entre menos efectivo', function () {
    $tipo = withholdingType(code: 'ISRV', rate: '12.5', scope: WithholdingScope::Sale);

    // Sin existencia no se puede vender: la Fase 5 lo bloquea.
    creditPurchaseFor($this, '600.00', 'FAC-STOCK');

    $venta = $this->sales->createAndIssue([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $this->warehouse->id,
        'customer_id' => $this->customer->id,
        'date' => '2026-03-05',
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $this->product->id, 'quantity' => '10', 'unit_price' => '1000.00']]);

    $this->receipts->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => '2026-03-20',
        'payment_method' => PaymentMethod::Transfer,
        'deposit_account_id' => $this->bank->id,
        'withholdings' => [['withholding_type_id' => $tipo->id]],
    ], [['receivable_id' => $venta->receivable->id, 'amount' => '11500.00']]);

    expect($venta->receivable->refresh()->balanceAmount()->isZero())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| El libro sigue cuadrado
|--------------------------------------------------------------------------
*/

it('mantiene el libro cuadrado con retenciones de los dos lados', function () {
    $compraTipo = withholdingType(code: 'ISRC', rate: '12.5');
    $ventaTipo = withholdingType(code: 'ISRV', rate: '12.5', scope: WithholdingScope::Sale);

    $compra = creditPurchaseFor($this, '777.77', 'FAC-R-99');

    $this->payments->create([
        'branch_id' => $this->branch->id,
        'supplier_id' => $this->supplier->id,
        'date' => '2026-03-15',
        'payment_method' => PaymentMethod::Transfer,
        'payment_account_id' => $this->bank->id,
        'withholdings' => [['withholding_type_id' => $compraTipo->id]],
    ], [['payable_id' => $compra->payable->id, 'amount' => '3333.33']]);

    $venta = $this->sales->createAndIssue([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $this->warehouse->id,
        'customer_id' => $this->customer->id,
        'date' => '2026-03-05',
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $this->product->id, 'quantity' => '5', 'unit_price' => '1111.11']]);
    // La compra del principio ya dejó existencia para esta venta.

    $this->receipts->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => '2026-03-20',
        'payment_method' => PaymentMethod::Transfer,
        'deposit_account_id' => $this->bank->id,
        'withholdings' => [['withholding_type_id' => $ventaTipo->id]],
    ], [['receivable_id' => $venta->receivable->id, 'amount' => '2222.22']]);

    $totales = DB::table('journal_entry_lines as l')
        ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
        ->where('e.status', JournalEntryStatus::Posted->value)
        ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
        ->first();

    expect(Money::of((string) $totales->debit)->equals(Money::of((string) $totales->credit)))->toBeTrue();
});
