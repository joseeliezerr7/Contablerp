<?php

declare(strict_types=1);

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Payables\Models\Payable;
use App\Domains\Payables\Services\PaymentService;
use App\Domains\Purchases\Services\PurchaseService;
use App\Domains\Receivables\Models\Receivable;
use App\Domains\Receivables\Services\ReceiptService;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Services\SaleService;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Segunda invariante del sistema: las cuentas de control deben cuadrar con su
 * auxiliar.
 *
 * El libro puede estar perfectamente cuadrado y aun así ser inútil: si alguien
 * mueve la cuenta de Clientes o la de Proveedores por fuera de los documentos,
 * el balance sigue cuadrando pero la antigüedad de saldos deja de coincidir
 * con él, y ya no se sabe cuál de los dos números es el bueno. Esta prueba
 * ejercita ventas, compras, cobros, pagos y anulaciones, y después compara el
 * saldo contable de cada cuenta de control contra la suma de los saldos de sus
 * documentos abiertos.
 */
beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();

    $this->sales = app(SaleService::class);
    $this->receipts = app(ReceiptService::class);
    $this->purchases = app(PurchaseService::class);
    $this->payments = app(PaymentService::class);

    $this->branch = mainBranch();
    $this->bank = account('1.1.02.01');
    $this->customers = account('1.1.03.01');
    $this->suppliers = account('2.1.01.01');
});

/**
 * Saldo contable de una cuenta: debe − haber sobre las partidas contabilizadas.
 */
function postedBalanceOf(int $accountId): Money
{
    $row = DB::table('journal_entry_lines as l')
        ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
        ->where('e.status', JournalEntryStatus::Posted->value)
        ->where('l.account_id', $accountId)
        ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
        ->first();

    return Money::of((string) $row->debit)->minus(Money::of((string) $row->credit));
}

/**
 * Mueve el ciclo completo de ventas y compras, incluidas las anulaciones.
 */
function exerciseSubledgers(object $ctx): void
{
    $cliente = makeCustomer(['credit_limit' => '500000.00', 'credit_days' => 30]);
    $proveedor = makeSupplier();
    $producto = makeProduct('1000.00');

    $credito = fn (string $price) => $ctx->sales->createAndIssue([
        'branch_id' => $ctx->branch->id,
        'customer_id' => $cliente->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $producto->id, 'quantity' => '1', 'unit_price' => $price]]);

    $compra = fn (string $price, string $invoice) => $ctx->purchases->createAndReceive([
        'branch_id' => $ctx->branch->id,
        'supplier_id' => $proveedor->id,
        'supplier_invoice_number' => $invoice,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $producto->id, 'quantity' => '1', 'unit_price' => $price]]);

    // Ventas al crédito: una se cobra entera, otra a medias, otra se anula.
    $completa = $credito('1000.00');
    $parcial = $credito('2000.00');
    $anulada = $credito('750.00');

    $ctx->receipts->create([
        'branch_id' => $ctx->branch->id,
        'customer_id' => $cliente->id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Cash,
        'deposit_account_id' => $ctx->bank->id,
    ], [
        ['receivable_id' => $completa->receivable->id, 'amount' => '1150.00'],
        ['receivable_id' => $parcial->receivable->id, 'amount' => '900.00'],
    ]);

    $ctx->sales->void($anulada, 'Anulada durante la prueba de invariante');

    // Un cobro que después se anula: el saldo debe volver a la factura.
    $devuelto = $ctx->receipts->create([
        'branch_id' => $ctx->branch->id,
        'customer_id' => $cliente->id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Transfer,
        'deposit_account_id' => $ctx->bank->id,
    ], [['receivable_id' => $parcial->receivable->id, 'amount' => '400.00']]);

    $ctx->receipts->void($devuelto, 'Transferencia rechazada durante la prueba');

    // Venta de contado: no debe tocar la cuenta de control en absoluto.
    $ctx->sales->createAndIssue([
        'branch_id' => $ctx->branch->id,
        'customer_id' => $cliente->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Cash,
        'deposit_account_id' => $ctx->bank->id,
    ], [['product_id' => $producto->id, 'quantity' => '3', 'unit_price' => '1000.00']]);

    // Compras al crédito: una se paga en parte, otra se anula.
    $porPagar = $compra('1000.00', 'FAC-INV-1');
    $aAnular = $compra('1300.00', 'FAC-INV-2');

    $ctx->payments->create([
        'branch_id' => $ctx->branch->id,
        'supplier_id' => $proveedor->id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Check,
        'reference' => 'CHQ-7001',
        'payment_account_id' => $ctx->bank->id,
    ], [['payable_id' => $porPagar->payable->id, 'amount' => '600.00']]);

    $ctx->purchases->void($aAnular->refresh(), 'Anulada durante la prueba de invariante');
}

it('cuadra la cuenta de Clientes con el auxiliar de cuentas por cobrar', function () {
    exerciseSubledgers($this);

    // Mismo filtro que alimenta la antigüedad de saldos: si el reporte y la
    // contabilidad no coinciden, el usuario ve dos verdades distintas.
    $auxiliar = Money::sum(
        Receivable::query()->outstanding()->get()->map(fn (Receivable $r) => $r->balanceAmount())->all()
    );
    $contable = postedBalanceOf($this->customers->id);

    expect($auxiliar->isPositive())->toBeTrue('La prueba no dejó ninguna cuenta por cobrar abierta')
        ->and($contable->equals($auxiliar))->toBeTrue(
            "Clientes descuadra: contabilidad {$contable->format()}, auxiliar {$auxiliar->format()}."
        );
});

it('cuadra la cuenta de Proveedores con el auxiliar de cuentas por pagar', function () {
    exerciseSubledgers($this);

    $auxiliar = Money::sum(
        Payable::query()->outstanding()->get()->map(fn (Payable $p) => $p->balanceAmount())->all()
    );
    // Proveedores es de naturaleza acreedora: su saldo contable es negativo
    // bajo la convención debe − haber.
    $contable = postedBalanceOf($this->suppliers->id)->negated();

    expect($auxiliar->isPositive())->toBeTrue('La prueba no dejó ninguna cuenta por pagar abierta')
        ->and($contable->equals($auxiliar))->toBeTrue(
            "Proveedores descuadra: contabilidad {$contable->format()}, auxiliar {$auxiliar->format()}."
        );
});

it('deja los datos de demostración con las cuentas de control cuadradas', function () {
    // Los asientos de ejemplo del seeder no deben tocar Clientes ni Proveedores:
    // esas cuentas son territorio exclusivo de los documentos.
    $this->seed(DatabaseSeeder::class);

    acrossCompanies(function () {
        $manuales = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('a.code', ['1.1.03.01', '2.1.01.01'])
            ->whereNull('e.source_type')
            ->where('e.status', JournalEntryStatus::Posted->value)
            ->pluck('e.number');

        expect($manuales)->toBeEmpty(
            'Asientos manuales contra cuentas de control: '.$manuales->implode(', ')
        );
    });
});
