<?php

declare(strict_types=1);

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Partners\Models\Customer;
use App\Domains\Receivables\Enums\ReceivableStatus;
use App\Domains\Receivables\Exceptions\ReceivableException;
use App\Domains\Receivables\Models\Receipt;
use App\Domains\Receivables\Services\ReceiptService;
use App\Domains\Receivables\Services\ReceivableService;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->sales = app(SaleService::class);
    $this->receipts = app(ReceiptService::class);
    $this->receivables = app(ReceivableService::class);

    $this->branch = mainBranch();
    $this->bank = account('1.1.02.01');
    $this->customer = makeCustomer(['credit_limit' => '500000.00', 'credit_days' => 30]);
    $this->product = makeProduct('1000.00');
});

/**
 * Emite una factura al crédito y devuelve la venta.
 */
function creditSale(SaleService $service, Customer $customer, int $branchId, int $productId, string $price, string $date = 'today'): Sale
{
    return $service->createAndIssue([
        'branch_id' => $branchId,
        'customer_id' => $customer->id,
        'date' => CarbonImmutable::parse($date)->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $productId, 'quantity' => '1', 'unit_price' => $price]]);
}

/*
|--------------------------------------------------------------------------
| Cobro
|--------------------------------------------------------------------------
*/

it('cobra una factura completa y la deja cancelada', function () {
    $sale = creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '1000.00');
    $receivable = $sale->receivable;

    $receipt = $this->receipts->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Cash,
        'deposit_account_id' => $this->bank->id,
    ], [['receivable_id' => $receivable->id, 'amount' => '1150.00']]);

    expect($receipt->amountMoney()->toString())->toBe('1150.0000')
        ->and($receipt->number)->toBe('REC-000001')
        ->and($receivable->refresh()->status)->toBe(ReceivableStatus::Paid)
        ->and($receivable->balanceAmount()->isZero())->toBeTrue();
});

it('acepta un abono parcial y deja el documento pendiente', function () {
    $sale = creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '1000.00');

    $this->receipts->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Cash,
        'deposit_account_id' => $this->bank->id,
    ], [['receivable_id' => $sale->receivable->id, 'amount' => '400.00']]);

    $receivable = $sale->receivable->refresh();

    expect($receivable->status)->toBe(ReceivableStatus::Open)
        ->and($receivable->paidAmount()->toString())->toBe('400.0000')
        ->and($receivable->balanceAmount()->toString())->toBe('750.0000');
});

it('aplica un recibo a varias facturas del mismo cliente', function () {
    $a = creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '1000.00');
    $b = creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '2000.00');

    $receipt = $this->receipts->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Transfer,
        'reference' => 'TRF-9981',
        'deposit_account_id' => $this->bank->id,
    ], [
        ['receivable_id' => $a->receivable->id, 'amount' => '1150.00'],
        ['receivable_id' => $b->receivable->id, 'amount' => '1000.00'],
    ]);

    expect($receipt->amountMoney()->toString())->toBe('2150.0000')
        ->and($receipt->applications)->toHaveCount(2)
        ->and($a->receivable->refresh()->status)->toBe(ReceivableStatus::Paid)
        ->and($b->receivable->refresh()->balanceAmount()->toString())->toBe('1300.0000');
});

it('genera la partida del cobro cuadrada', function () {
    $sale = creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '1000.00');

    $receipt = $this->receipts->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Check,
        'reference' => 'CHQ-4410',
        'deposit_account_id' => $this->bank->id,
    ], [['receivable_id' => $sale->receivable->id, 'amount' => '1150.00']]);

    $entry = $receipt->journalEntry();
    $lines = $entry->lines->keyBy('account_id');

    expect($entry->isBalanced())->toBeTrue()
        ->and($entry->status)->toBe(JournalEntryStatus::Posted)
        // Entra al banco y baja la cuenta del cliente.
        ->and($lines[$this->bank->id]->debitAmount()->toString())->toBe('1150.0000')
        ->and($lines[account('1.1.03.01')->id]->creditAmount()->toString())->toBe('1150.0000');
});

/*
|--------------------------------------------------------------------------
| Validaciones
|--------------------------------------------------------------------------
*/

it('rechaza cobrar más que el saldo del documento', function () {
    $sale = creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '1000.00');

    expect(fn () => $this->receipts->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Cash,
        'deposit_account_id' => $this->bank->id,
    ], [['receivable_id' => $sale->receivable->id, 'amount' => '5000.00']]))
        ->toThrow(ReceivableException::class, 'su saldo es');
});

it('rechaza aplicar un recibo a la factura de otro cliente', function () {
    $otro = makeCustomer(['credit_limit' => '100000.00', 'credit_days' => 30]);
    $sale = creditSale($this->sales, $otro, $this->branch->id, $this->product->id, '1000.00');

    expect(fn () => $this->receipts->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Cash,
        'deposit_account_id' => $this->bank->id,
    ], [['receivable_id' => $sale->receivable->id, 'amount' => '100.00']]))
        ->toThrow(ReceivableException::class, 'es de otro cliente');
});

it('rechaza un recibo sin aplicaciones', function () {
    expect(fn () => $this->receipts->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Cash,
        'deposit_account_id' => $this->bank->id,
    ], []))->toThrow(ReceivableException::class, 'al menos a un documento');
});

it('no deja el saldo negativo ni con cobros sucesivos', function () {
    $sale = creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '1000.00');

    $header = [
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Cash,
        'deposit_account_id' => $this->bank->id,
    ];

    $this->receipts->create($header, [['receivable_id' => $sale->receivable->id, 'amount' => '1000.00']]);

    expect(fn () => $this->receipts->create($header, [
        ['receivable_id' => $sale->receivable->id, 'amount' => '200.00'],
    ]))->toThrow(ReceivableException::class);

    expect($sale->receivable->refresh()->balanceAmount()->toString())->toBe('150.0000');
});

/*
|--------------------------------------------------------------------------
| Anulación
|--------------------------------------------------------------------------
*/

it('anula el recibo y devuelve el saldo a la factura', function () {
    $sale = creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '1000.00');

    $receipt = $this->receipts->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Cash,
        'deposit_account_id' => $this->bank->id,
    ], [['receivable_id' => $sale->receivable->id, 'amount' => '1150.00']]);

    expect($sale->receivable->refresh()->status)->toBe(ReceivableStatus::Paid);

    $this->receipts->void($receipt, 'Cheque devuelto por el banco');

    $receivable = $sale->receivable->refresh();

    expect($receipt->refresh()->isVoided())->toBeTrue()
        ->and($receivable->status)->toBe(ReceivableStatus::Open)
        ->and($receivable->paidAmount()->isZero())->toBeTrue()
        ->and($receivable->balanceAmount()->toString())->toBe('1150.0000');

    $entry = acrossCompanies(fn () => JournalEntry::acrossCompanies()
        ->where('source_type', 'receipt')->where('source_id', $receipt->id)->firstOrFail());

    expect($entry->status)->toBe(JournalEntryStatus::Voided);
});

it('permite anular la factura después de anular su recibo', function () {
    $sale = creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '1000.00');

    $receipt = $this->receipts->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Cash,
        'deposit_account_id' => $this->bank->id,
    ], [['receivable_id' => $sale->receivable->id, 'amount' => '1150.00']]);

    $this->receipts->void($receipt, 'Cobro mal aplicado');
    $anulada = $this->sales->void($sale->refresh(), 'Factura emitida por error');

    expect($anulada->isVoided())->toBeTrue()
        ->and($anulada->receivable->refresh()->status)->toBe(ReceivableStatus::Voided);
});

it('exige motivo para anular el recibo', function () {
    $sale = creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '1000.00');

    $receipt = $this->receipts->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Cash,
        'deposit_account_id' => $this->bank->id,
    ], [['receivable_id' => $sale->receivable->id, 'amount' => '100.00']]);

    expect(fn () => $this->receipts->void($receipt, ''))
        ->toThrow(ReceivableException::class, 'motivo');
});

/*
|--------------------------------------------------------------------------
| Antigüedad y estado de cuenta
|--------------------------------------------------------------------------
*/

it('clasifica la antigüedad de saldos por tramos', function () {
    $hoy = CarbonImmutable::parse('2026-06-30');

    // Vence en 30 días desde cada fecha de emisión.
    creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '1000.00', '2026-06-15'); // corriente
    creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '2000.00', '2026-05-10'); // 21 días
    creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '3000.00', '2026-03-01'); // 91 días

    $aging = $this->receivables->aging($hoy);

    expect($aging['totals']['current']->toString())->toBe('1150.0000')
        ->and($aging['totals']['d30']->toString())->toBe('2300.0000')
        ->and($aging['totals']['over']->toString())->toBe('3450.0000')
        ->and($aging['totals']['total']->toString())->toBe('6900.0000')
        ->and($aging['rows'])->toHaveCount(1);
});

it('excluye de la antigüedad las facturas ya cobradas', function () {
    $sale = creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '1000.00');

    $this->receipts->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Cash,
        'deposit_account_id' => $this->bank->id,
    ], [['receivable_id' => $sale->receivable->id, 'amount' => '1150.00']]);

    expect($this->receivables->aging()['totals']['total']->isZero())->toBeTrue();
});

it('arma el estado de cuenta con cargos, abonos y saldo acumulado', function () {
    $a = creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '1000.00', '2026-03-05');
    creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '2000.00', '2026-03-10');

    $this->receipts->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => '2026-03-15',
        'payment_method' => PaymentMethod::Cash,
        'deposit_account_id' => $this->bank->id,
    ], [['receivable_id' => $a->receivable->id, 'amount' => '1150.00']]);

    $estado = $this->receivables->statement($this->customer, '2026-03-01', '2026-03-31');

    expect($estado['opening']->isZero())->toBeTrue()
        ->and($estado['rows'])->toHaveCount(3)
        // 1150 + 2300 − 1150 = 2300
        ->and($estado['closing']->toString())->toBe('2300.0000');
});

it('coincide el saldo del cliente con la suma de sus documentos abiertos', function () {
    creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '1000.00');
    creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '2000.00');

    expect($this->customer->refresh()->outstandingBalance()->toString())->toBe('3450.0000')
        ->and($this->receivables->balanceAt($this->customer, now())->toString())->toBe('3450.0000');
});

it('aísla los recibos entre empresas', function () {
    $sale = creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '1000.00');

    $this->receipts->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Cash,
        'deposit_account_id' => $this->bank->id,
    ], [['receivable_id' => $sale->receivable->id, 'amount' => '100.00']]);

    $otra = accountingCompany();
    actingAsUserOf($otra, role: PermissionCatalog::ACCOUNTANT);

    expect(Receipt::query()->count())->toBe(0)
        ->and(acrossCompanies(fn () => Receipt::acrossCompanies()->count()))->toBe(1);
});

it('mantiene el libro cuadrado tras facturar, cobrar y anular', function () {
    $a = creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '1000.00');
    $b = creditSale($this->sales, $this->customer, $this->branch->id, $this->product->id, '2500.00');

    $receipt = $this->receipts->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Cash,
        'deposit_account_id' => $this->bank->id,
    ], [['receivable_id' => $a->receivable->id, 'amount' => '1150.00']]);

    $this->receipts->void($receipt, 'Prueba de anulación');
    $this->sales->void($b->refresh(), 'Prueba de anulación');

    $totals = DB::table('journal_entry_lines as l')
        ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
        ->where('e.status', JournalEntryStatus::Posted->value)
        ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
        ->first();

    expect(Money::of((string) $totals->debit)->equals(Money::of((string) $totals->credit)))->toBeTrue();
});
