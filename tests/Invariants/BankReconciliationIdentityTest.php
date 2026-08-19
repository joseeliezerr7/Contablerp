<?php

declare(strict_types=1);

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Payables\Services\PaymentService;
use App\Domains\Purchases\Services\PurchaseService;
use App\Domains\Receivables\Services\ReceiptService;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Services\SaleService;
use App\Domains\Treasury\Models\Check;
use App\Domains\Treasury\Services\BankAccountService;
use App\Domains\Treasury\Services\BankReconciliationService;
use App\Domains\Treasury\Services\CheckService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Criterio de aceptación de la Fase 6:
 *
 *     saldo del extracto + depósitos en tránsito − cheques pendientes
 *         = saldo en libros a la fecha de corte
 *
 * La identidad no es un cálculo aparte que haya que mantener sincronizado: los
 * tres sumandos salen del mismo sitio —las líneas del libro sobre la cuenta
 * bancaria, marcadas o no—, y por eso se cumple por construcción. Lo que esta
 * prueba comprueba es que **todos los caminos que mueven el banco** produzcan
 * líneas conciliables: cobros, pagos, compras de contado, ventas de contado,
 * partidas manuales y anulaciones.
 *
 * Si una fase futura añade una forma de mover dinero que no deje línea en la
 * cuenta bancaria, o que la deje mal, es aquí donde se nota.
 */
beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();

    $this->banks = app(BankAccountService::class);
    $this->reconciliations = app(BankReconciliationService::class);
    $this->checks = app(CheckService::class);
    $this->engine = app(AccountingEngine::class);
    $this->sales = app(SaleService::class);
    $this->purchases = app(PurchaseService::class);
    $this->receipts = app(ReceiptService::class);
    $this->payments = app(PaymentService::class);

    $this->branch = mainBranch();
    $this->warehouse = warehouse();
    $this->bankGl = account('1.1.02.01');

    $this->bankAccount = $this->banks->create([
        'account_id' => $this->bankGl->id,
        'bank_name' => 'Banco Atlántida',
        'number' => '01-000-111222',
        'next_check_number' => 5001,
    ]);

    $this->customer = makeCustomer(['credit_limit' => '900000.00', 'credit_days' => 30]);
    $this->supplier = makeSupplier();
});

/**
 * Mueve el banco por todos los caminos que existen hoy.
 */
function exerciseEveryBankPath(object $ctx): void
{
    $producto = makeProduct('900.00', tracked: true);

    // Capital inicial: una partida manual.
    $ctx->engine->post(
        JournalDraft::on('2026-05-01', 'Aporte de capital')
            ->debit($ctx->bankGl->id, '200000.00')
            ->credit(account('3.1.01')->id, '200000.00')
    );

    // Compra de contado: sale dinero del banco.
    $ctx->purchases->createAndReceive([
        'branch_id' => $ctx->branch->id,
        'warehouse_id' => $ctx->warehouse->id,
        'supplier_id' => $ctx->supplier->id,
        'supplier_invoice_number' => 'FAC-B-01',
        'date' => '2026-05-03',
        'payment_condition' => PaymentCondition::Cash,
        'payment_account_id' => $ctx->bankGl->id,
    ], [['product_id' => $producto->id, 'quantity' => '30', 'unit_price' => '633.33']]);

    // Venta de contado: entra dinero al banco.
    $ctx->sales->createAndIssue([
        'branch_id' => $ctx->branch->id,
        'warehouse_id' => $ctx->warehouse->id,
        'customer_id' => $ctx->customer->id,
        'date' => '2026-05-06',
        'payment_condition' => PaymentCondition::Cash,
        'deposit_account_id' => $ctx->bankGl->id,
    ], [['product_id' => $producto->id, 'quantity' => '11', 'unit_price' => '977.77']]);

    // Venta al crédito y su cobro por transferencia.
    $ventaCredito = $ctx->sales->createAndIssue([
        'branch_id' => $ctx->branch->id,
        'warehouse_id' => $ctx->warehouse->id,
        'customer_id' => $ctx->customer->id,
        'date' => '2026-05-08',
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $producto->id, 'quantity' => '7', 'unit_price' => '1011.11']]);

    $ctx->receipts->create([
        'branch_id' => $ctx->branch->id,
        'customer_id' => $ctx->customer->id,
        'date' => '2026-05-12',
        'payment_method' => PaymentMethod::Transfer,
        'reference' => 'TRF-9001',
        'deposit_account_id' => $ctx->bankGl->id,
    ], [['receivable_id' => $ventaCredito->receivable->id, 'amount' => '3333.33']]);

    // Compra al crédito y su pago con cheque.
    $compraCredito = $ctx->purchases->createAndReceive([
        'branch_id' => $ctx->branch->id,
        'warehouse_id' => $ctx->warehouse->id,
        'supplier_id' => $ctx->supplier->id,
        'supplier_invoice_number' => 'FAC-B-02',
        'date' => '2026-05-10',
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $producto->id, 'quantity' => '9', 'unit_price' => '711.11']]);

    $ctx->payments->create([
        'branch_id' => $ctx->branch->id,
        'supplier_id' => $ctx->supplier->id,
        'date' => '2026-05-15',
        'payment_method' => PaymentMethod::Check,
        'payment_account_id' => $ctx->bankGl->id,
    ], [['payable_id' => $compraCredito->payable->id, 'amount' => '2500.00']]);

    // Un cobro que después se anula: la reversión también toca el banco.
    $anulado = $ctx->receipts->create([
        'branch_id' => $ctx->branch->id,
        'customer_id' => $ctx->customer->id,
        'date' => '2026-05-18',
        'payment_method' => PaymentMethod::Transfer,
        'reference' => 'TRF-9002',
        'deposit_account_id' => $ctx->bankGl->id,
    ], [['receivable_id' => $ventaCredito->receivable->id, 'amount' => '1200.00']]);

    $ctx->receipts->void($anulado, 'Transferencia rechazada');

    // Comisiones e intereses: partidas manuales, que en un extracto real son la
    // mitad de las líneas.
    $ctx->engine->post(
        JournalDraft::on('2026-05-28', 'Comisiones bancarias')
            ->debit(account('6.3.02')->id, '387.55')
            ->credit($ctx->bankGl->id, '387.55')
    );

    $ctx->engine->post(
        JournalDraft::on('2026-05-30', 'Intereses ganados')
            ->debit($ctx->bankGl->id, '129.44')
            ->credit(account('4.2.01')->id, '129.44')
    );
}

it('cumple la identidad cuando el extracto refleja todo el libro', function () {
    exerciseEveryBankPath($this);

    $libros = $this->banks->bookBalance($this->bankAccount, '2026-05-31');

    $conciliacion = $this->reconciliations->open($this->bankAccount, '2026-05-31', $libros);
    $conciliacion = $this->reconciliations->markAll($conciliacion);

    expect($conciliacion->bookBalance()->equals($libros))->toBeTrue()
        ->and($conciliacion->isBalanced())->toBeTrue(
            "Quedaron {$conciliacion->differenceAmount()->format()} sin explicar."
        );
});

it('cumple la identidad con partidas sin marcar', function () {
    exerciseEveryBankPath($this);

    $conciliacion = $this->reconciliations->open($this->bankAccount, '2026-05-31', Money::zero());

    // Se marca solo la mitad de las partidas, la que fuera.
    $items = $this->reconciliations->items($conciliacion);
    foreach ($items->take((int) ceil($items->count() / 2)) as $line) {
        $conciliacion = $this->reconciliations->mark($conciliacion, $line->id);
    }

    // El extracto que haría cuadrar esto es, por definición, la suma de lo
    // marcado. Se recalcula la conciliación con ese saldo y debe dar cero.
    $extracto = $conciliacion->bookBalance()
        ->minus($conciliacion->depositsInTransit())
        ->plus($conciliacion->outstandingChecks());

    $conciliacion->forceFill(['statement_balance' => $extracto->toString()])->save();
    $conciliacion = $this->reconciliations->recalculate($conciliacion);

    expect($conciliacion->isBalanced())->toBeTrue(
        "Con extracto {$extracto->format()} la conciliación debería cuadrar."
    );
});

it('mantiene la identidad mes a mes', function () {
    exerciseEveryBankPath($this);

    // Mayo se concilia entero.
    $mayo = $this->reconciliations->open(
        $this->bankAccount,
        '2026-05-31',
        $this->banks->bookBalance($this->bankAccount, '2026-05-31'),
    );
    $mayo = $this->reconciliations->markAll($mayo);
    $this->reconciliations->close($mayo);

    // En junio se mueve más dinero.
    $this->engine->post(
        JournalDraft::on('2026-06-04', 'Otro aporte')
            ->debit($this->bankGl->id, '15000.00')
            ->credit(account('3.1.01')->id, '15000.00')
    );

    $this->engine->post(
        JournalDraft::on('2026-06-20', 'Comisión de junio')
            ->debit(account('6.3.02')->id, '412.90')
            ->credit($this->bankGl->id, '412.90')
    );

    $junio = $this->reconciliations->open(
        $this->bankAccount,
        '2026-06-30',
        $this->banks->bookBalance($this->bankAccount, '2026-06-30'),
    );
    $junio = $this->reconciliations->markAll($junio);

    expect($junio->depositsInTransit()->isZero())->toBeTrue('Mayo no debe reaparecer en junio')
        ->and($junio->outstandingChecks()->isZero())->toBeTrue()
        ->and($junio->isBalanced())->toBeTrue();
});

it('deja el cheque girado como pendiente hasta que el banco lo cobra', function () {
    exerciseEveryBankPath($this);

    $cheque = Check::query()->sole();

    expect($cheque->isOutstanding())->toBeTrue()
        ->and($cheque->amountMoney()->toString())->toBe('2500.0000')
        ->and($this->checks->outstandingTotal($this->bankAccount, '2026-05-31')->toString())
        ->toBe('2500.0000');

    $this->checks->markCleared($cheque, '2026-06-03');

    // A fin de mayo seguía pendiente; a fin de junio ya no.
    expect($this->checks->outstandingTotal($this->bankAccount, '2026-05-31')->toString())->toBe('2500.0000')
        ->and($this->checks->outstandingTotal($this->bankAccount, '2026-06-30')->isZero())->toBeTrue();
});

it('no cuenta el cheque dos veces en la aritmética', function () {
    exerciseEveryBankPath($this);

    $libros = $this->banks->bookBalance($this->bankAccount, '2026-05-31');

    $conciliacion = $this->reconciliations->open($this->bankAccount, '2026-05-31', $libros);
    $conciliacion = $this->reconciliations->markAll($conciliacion);

    // Existe un cheque pendiente de 2 500 en la tabla de cheques, pero su
    // partida sí está marcada: la conciliación no debe restarlo otra vez.
    expect(Check::query()->count())->toBe(1)
        ->and($conciliacion->outstandingChecks()->isZero())->toBeTrue()
        ->and($conciliacion->isBalanced())->toBeTrue();
});

it('mantiene el libro contable cuadrado con la tesorería en marcha', function () {
    exerciseEveryBankPath($this);

    $totales = DB::table('journal_entry_lines as l')
        ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
        ->where('e.status', JournalEntryStatus::Posted->value)
        ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
        ->first();

    expect(Money::of((string) $totales->debit)->equals(Money::of((string) $totales->credit)))->toBeTrue();
});
