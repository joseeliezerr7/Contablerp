<?php

declare(strict_types=1);

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Partners\Models\Supplier;
use App\Domains\Payables\Enums\PayableStatus;
use App\Domains\Payables\Exceptions\PayableException;
use App\Domains\Payables\Models\Payment;
use App\Domains\Payables\Services\PayableService;
use App\Domains\Payables\Services\PaymentService;
use App\Domains\Purchases\Models\Purchase;
use App\Domains\Purchases\Services\PurchaseService;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->purchases = app(PurchaseService::class);
    $this->payments = app(PaymentService::class);
    $this->payables = app(PayableService::class);

    $this->branch = mainBranch();
    $this->bank = account('1.1.02.01');
    $this->supplier = makeSupplier();
    $this->product = makeProduct('0');
});

/**
 * Registra una compra al crédito y la devuelve.
 */
function creditPurchase(PurchaseService $service, Supplier $supplier, int $branchId, int $productId, string $price, string $invoice, string $date = 'today'): Purchase
{
    return $service->createAndReceive([
        'branch_id' => $branchId,
        'supplier_id' => $supplier->id,
        'supplier_invoice_number' => $invoice,
        'date' => CarbonImmutable::parse($date)->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $productId, 'quantity' => '1', 'unit_price' => $price]]);
}

/**
 * @return array<string, mixed>
 */
function paymentData(int $branchId, int $supplierId, int $accountId): array
{
    return [
        'branch_id' => $branchId,
        'supplier_id' => $supplierId,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Transfer,
        'reference' => 'TRF-1001',
        'payment_account_id' => $accountId,
    ];
}

it('paga una factura completa y la deja cancelada', function () {
    $compra = creditPurchase($this->purchases, $this->supplier, $this->branch->id, $this->product->id, '1000.00', 'FAC-9001');

    $pago = $this->payments->create(
        paymentData($this->branch->id, $this->supplier->id, $this->bank->id),
        [['payable_id' => $compra->payable->id, 'amount' => '1150.00']],
    );

    expect($pago->amountMoney()->toString())->toBe('1150.0000')
        ->and($pago->number)->toBe('PAG-000001')
        ->and($compra->payable->refresh()->status)->toBe(PayableStatus::Paid)
        ->and($compra->payable->balanceAmount()->isZero())->toBeTrue();
});

it('genera la partida del pago cuadrada', function () {
    $compra = creditPurchase($this->purchases, $this->supplier, $this->branch->id, $this->product->id, '1000.00', 'FAC-9002');

    $pago = $this->payments->create(
        paymentData($this->branch->id, $this->supplier->id, $this->bank->id),
        [['payable_id' => $compra->payable->id, 'amount' => '1150.00']],
    );

    $lines = $pago->journalEntry()->lines->keyBy('account_id');

    expect($pago->journalEntry()->isBalanced())->toBeTrue()
        // Baja la deuda con el proveedor y sale el dinero del banco.
        ->and($lines[account('2.1.01.01')->id]->debitAmount()->toString())->toBe('1150.0000')
        ->and($lines[$this->bank->id]->creditAmount()->toString())->toBe('1150.0000');
});

it('aplica un pago a varias facturas del mismo proveedor', function () {
    $a = creditPurchase($this->purchases, $this->supplier, $this->branch->id, $this->product->id, '1000.00', 'FAC-9003');
    $b = creditPurchase($this->purchases, $this->supplier, $this->branch->id, $this->product->id, '2000.00', 'FAC-9004');

    $pago = $this->payments->create(
        paymentData($this->branch->id, $this->supplier->id, $this->bank->id),
        [
            ['payable_id' => $a->payable->id, 'amount' => '1150.00'],
            ['payable_id' => $b->payable->id, 'amount' => '1000.00'],
        ],
    );

    expect($pago->amountMoney()->toString())->toBe('2150.0000')
        ->and($a->payable->refresh()->status)->toBe(PayableStatus::Paid)
        ->and($b->payable->refresh()->balanceAmount()->toString())->toBe('1300.0000');
});

it('rechaza pagar más que el saldo del documento', function () {
    $compra = creditPurchase($this->purchases, $this->supplier, $this->branch->id, $this->product->id, '1000.00', 'FAC-9005');

    expect(fn () => $this->payments->create(
        paymentData($this->branch->id, $this->supplier->id, $this->bank->id),
        [['payable_id' => $compra->payable->id, 'amount' => '9999.00']],
    ))->toThrow(PayableException::class, 'su saldo es');
});

it('rechaza aplicar un pago a la factura de otro proveedor', function () {
    $otro = makeSupplier();
    $compra = creditPurchase($this->purchases, $otro, $this->branch->id, $this->product->id, '1000.00', 'FAC-9006');

    expect(fn () => $this->payments->create(
        paymentData($this->branch->id, $this->supplier->id, $this->bank->id),
        [['payable_id' => $compra->payable->id, 'amount' => '100.00']],
    ))->toThrow(PayableException::class, 'es de otro proveedor');
});

it('anula el pago y devuelve el saldo a la factura', function () {
    $compra = creditPurchase($this->purchases, $this->supplier, $this->branch->id, $this->product->id, '1000.00', 'FAC-9007');

    $pago = $this->payments->create(
        paymentData($this->branch->id, $this->supplier->id, $this->bank->id),
        [['payable_id' => $compra->payable->id, 'amount' => '1150.00']],
    );

    $this->payments->void($pago, 'Cheque anulado por el banco');

    $payable = $compra->payable->refresh();

    expect($pago->refresh()->isVoided())->toBeTrue()
        ->and($payable->status)->toBe(PayableStatus::Open)
        ->and($payable->balanceAmount()->toString())->toBe('1150.0000');

    $entry = acrossCompanies(fn () => JournalEntry::acrossCompanies()
        ->where('source_type', 'payment')->where('source_id', $pago->id)->firstOrFail());

    expect($entry->status)->toBe(JournalEntryStatus::Voided);
});

it('permite anular la compra después de anular su pago', function () {
    $compra = creditPurchase($this->purchases, $this->supplier, $this->branch->id, $this->product->id, '1000.00', 'FAC-9008');

    $pago = $this->payments->create(
        paymentData($this->branch->id, $this->supplier->id, $this->bank->id),
        [['payable_id' => $compra->payable->id, 'amount' => '1150.00']],
    );

    $this->payments->void($pago, 'Pago mal aplicado');
    $anulada = $this->purchases->void($compra->refresh(), 'Compra registrada por error');

    expect($anulada->isVoided())->toBeTrue()
        ->and($anulada->payable->refresh()->status)->toBe(PayableStatus::Voided);
});

it('clasifica la antigüedad de saldos por pagar', function () {
    $hoy = CarbonImmutable::parse('2026-06-30');

    creditPurchase($this->purchases, $this->supplier, $this->branch->id, $this->product->id, '1000.00', 'FAC-A1', '2026-06-15');
    creditPurchase($this->purchases, $this->supplier, $this->branch->id, $this->product->id, '2000.00', 'FAC-A2', '2026-05-10');
    creditPurchase($this->purchases, $this->supplier, $this->branch->id, $this->product->id, '3000.00', 'FAC-A3', '2026-03-01');

    $aging = $this->payables->aging($hoy);

    expect($aging['totals']['current']->toString())->toBe('1150.0000')
        ->and($aging['totals']['d30']->toString())->toBe('2300.0000')
        ->and($aging['totals']['over']->toString())->toBe('3450.0000')
        ->and($aging['totals']['total']->toString())->toBe('6900.0000');
});

it('arma el estado de cuenta del proveedor', function () {
    $a = creditPurchase($this->purchases, $this->supplier, $this->branch->id, $this->product->id, '1000.00', 'FAC-B1', '2026-03-05');
    creditPurchase($this->purchases, $this->supplier, $this->branch->id, $this->product->id, '2000.00', 'FAC-B2', '2026-03-10');

    $this->payments->create([
        'branch_id' => $this->branch->id,
        'supplier_id' => $this->supplier->id,
        'date' => '2026-03-15',
        'payment_method' => PaymentMethod::Check,
        'reference' => 'CHQ-501',
        'payment_account_id' => $this->bank->id,
    ], [['payable_id' => $a->payable->id, 'amount' => '1150.00']]);

    $estado = $this->payables->statement($this->supplier, '2026-03-01', '2026-03-31');

    expect($estado['opening']->isZero())->toBeTrue()
        ->and($estado['rows'])->toHaveCount(3)
        ->and($estado['closing']->toString())->toBe('2300.0000');
});

it('coincide el saldo del proveedor con sus documentos abiertos', function () {
    creditPurchase($this->purchases, $this->supplier, $this->branch->id, $this->product->id, '1000.00', 'FAC-C1');
    creditPurchase($this->purchases, $this->supplier, $this->branch->id, $this->product->id, '2000.00', 'FAC-C2');

    expect($this->supplier->refresh()->outstandingBalance()->toString())->toBe('3450.0000')
        ->and($this->payables->balanceAt($this->supplier, now())->toString())->toBe('3450.0000');
});

it('aísla los pagos entre empresas', function () {
    $compra = creditPurchase($this->purchases, $this->supplier, $this->branch->id, $this->product->id, '1000.00', 'FAC-D1');

    $this->payments->create(
        paymentData($this->branch->id, $this->supplier->id, $this->bank->id),
        [['payable_id' => $compra->payable->id, 'amount' => '100.00']],
    );

    $otra = accountingCompany();
    actingAsUserOf($otra, role: PermissionCatalog::ACCOUNTANT);

    expect(Payment::query()->count())->toBe(0)
        ->and(acrossCompanies(fn () => Payment::acrossCompanies()->count()))->toBe(1);
});

it('mantiene el libro cuadrado tras comprar, pagar y anular', function () {
    $a = creditPurchase($this->purchases, $this->supplier, $this->branch->id, $this->product->id, '1000.00', 'FAC-E1');
    $b = creditPurchase($this->purchases, $this->supplier, $this->branch->id, $this->product->id, '2500.00', 'FAC-E2');

    $pago = $this->payments->create(
        paymentData($this->branch->id, $this->supplier->id, $this->bank->id),
        [['payable_id' => $a->payable->id, 'amount' => '1150.00']],
    );

    $this->payments->void($pago, 'Prueba de anulación');
    $this->purchases->void($b->refresh(), 'Prueba de anulación');

    $totals = DB::table('journal_entry_lines as l')
        ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
        ->where('e.status', JournalEntryStatus::Posted->value)
        ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
        ->first();

    expect(Money::of((string) $totals->debit)->equals(Money::of((string) $totals->credit)))->toBeTrue();
});
