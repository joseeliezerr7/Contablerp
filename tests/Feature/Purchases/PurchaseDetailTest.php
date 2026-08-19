<?php

declare(strict_types=1);

use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Payables\Models\Payment;
use App\Domains\Payables\Services\PaymentService;
use App\Domains\Purchases\Models\Purchase;
use App\Domains\Purchases\Services\PurchaseService;
// `PaymentCondition` vive en Sales y la comparten los dos módulos: contado y
// crédito significan lo mismo se venda o se compre.
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Livewire\Purchases\PaymentShow;
use App\Livewire\Purchases\PurchaseShow;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Livewire\Livewire;

/**
 * Ver una compra y un pago a proveedor.
 *
 * Es el espejo de ventas, pero el hueco era peor: una compra recibida **no tiene
 * PDF**, así que no había absolutamente nada que mirar —ni siquiera qué se
 * compró—. Del pago solo salía «anular», y el monto por sí solo no dice contra
 * qué facturas se aplicó ni cuánto se le retuvo al proveedor.
 */
beforeEach(function () {
    $this->company = accountingCompany();
    $this->accountant = actingAsUserOf($this->company, role: PermissionCatalog::ACCOUNTANT);
    $this->supplier = makeSupplier();
});

/**
 * Compra a crédito de 100 unidades a 100: 10 000 + ISV = 11 500.
 */
function detailPurchase(): Purchase
{
    return app(PurchaseService::class)->createAndReceive([
        'branch_id' => mainBranch()->id,
        'warehouse_id' => warehouse()->id,
        'supplier_id' => test()->supplier->id,
        'supplier_invoice_number' => '000-001-01-00009999',
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [
        ['product_id' => makeProduct('100.00')->id, 'quantity' => '100', 'unit_price' => '100.00'],
    ]);
}

function detailPayment(Purchase $purchase, string $amount): Payment
{
    return app(PaymentService::class)->create([
        'branch_id' => mainBranch()->id,
        'supplier_id' => $purchase->supplier_id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Transfer,
        'payment_account_id' => account('1.1.02.01')->id,
        'reference' => 'TRF-900',
    ], [
        ['payable_id' => $purchase->payable->id, 'amount' => $amount],
    ]);
}

/*
|--------------------------------------------------------------------------
| La compra
|--------------------------------------------------------------------------
*/

it('muestra qué se compró, con sus totales', function () {
    $purchase = detailPurchase();

    Livewire::test(PurchaseShow::class, ['purchase' => $purchase->id])
        ->assertSee($purchase->number)
        ->assertSee($this->supplier->name)
        // La factura del proveedor, que es por la que se busca una compra.
        ->assertSee('000-001-01-00009999')
        ->assertSee('10,000.00')
        ->assertSee('11,500.00');
});

it('dice si cada renglón entró al inventario o se fue a gasto', function () {
    // Es la distinción que decide medio asiento: la mercadería entra al kardex,
    // el gasto se va directo a resultados.
    //
    // `goesToInventory()` lee `track_inventory` del producto, y la pantalla trae
    // esa columna en el eager load a propósito: con
    // `preventAccessingMissingAttributes`, una columna que no se trajo revienta
    // en vez de devolver null. Sin esta prueba, un `select` de tres columnas
    // volvería a tumbar la pantalla.
    $purchase = app(PurchaseService::class)->createAndReceive([
        'branch_id' => mainBranch()->id,
        'warehouse_id' => warehouse()->id,
        'supplier_id' => $this->supplier->id,
        'supplier_invoice_number' => '000-001-01-00008888',
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Cash,
        'payment_account_id' => account('1.1.02.01')->id,
    ], [
        ['product_id' => makeProduct('100.00', tracked: true)->id, 'quantity' => '10', 'unit_price' => '100.00'],
        ['product_id' => makeProduct('50.00')->id, 'quantity' => '1', 'unit_price' => '50.00'],
    ]);

    Livewire::test(PurchaseShow::class, ['purchase' => $purchase->id])
        ->assertOk()
        ->assertSee('Inventario');

    $items = $purchase->items()->with('product:id,track_inventory')->get();

    expect($items->filter->goesToInventory())->toHaveCount(1)
        ->and($items->reject->goesToInventory())->toHaveCount(1);
});

it('muestra la cuenta por pagar con los pagos aplicados', function () {
    $purchase = detailPurchase();
    detailPayment($purchase, '4000.00');

    Livewire::test(PurchaseShow::class, ['purchase' => $purchase->id])
        ->assertSee('Cuenta por pagar')
        ->assertSee('4,000.00')
        // 11 500 − 4 000.
        ->assertSee('7,500.00');
});

/*
|--------------------------------------------------------------------------
| El pago
|--------------------------------------------------------------------------
*/

it('dice contra qué facturas se aplicó el pago', function () {
    $purchase = detailPurchase();
    $payment = detailPayment($purchase, '4000.00');

    Livewire::test(PaymentShow::class, ['payment' => $payment->id])
        ->assertSee($payment->number)
        ->assertSee('Facturas que canceló')
        ->assertSee($purchase->payable->document_number)
        ->assertSee('4,000.00')
        ->assertSee('7,500.00');
});

it('enlaza el pago con su partida contable', function () {
    $purchase = detailPurchase();
    $payment = detailPayment($purchase, '1000.00');

    Livewire::test(PaymentShow::class, ['payment' => $payment->id])
        ->assertSee($payment->journalEntry()->number);
});

it('muestra el pago anulado con su motivo', function () {
    $purchase = detailPurchase();
    $payment = detailPayment($purchase, '1000.00');

    app(PaymentService::class)->void($payment, 'Se giró el cheque al proveedor equivocado');

    Livewire::test(PaymentShow::class, ['payment' => $payment->id])
        ->assertSee('Pago anulado')
        ->assertSee('Se giró el cheque al proveedor equivocado');
});

/*
|--------------------------------------------------------------------------
| Aislamiento, permisos y rutas
|--------------------------------------------------------------------------
*/

it('no abre la compra de otra empresa', function () {
    $otra = accountingCompany();

    $ajena = app(CompanyContext::class)->runFor($otra, function (): Purchase {
        actingAsUserOf(app(CompanyContext::class)->companyOrFail(), role: PermissionCatalog::ACCOUNTANT);
        test()->supplier = makeSupplier();

        return detailPurchase();
    });

    $this->actingAs($this->accountant);
    app(CompanyContext::class)->set($this->company);

    Livewire::test(PurchaseShow::class, ['purchase' => $ajena->id]);
})->throws(ModelNotFoundException::class);

it('le niega la compra a quien no ve compras', function () {
    $purchase = detailPurchase();

    actingAsUserOf($this->company, role: PermissionCatalog::SALESPERSON);

    $this->get(route('purchases.show', $purchase->id))->assertForbidden();
});

it('ofrece «Ver» en los dos listados', function () {
    $purchase = detailPurchase();
    $payment = detailPayment($purchase, '1000.00');

    $this->get(route('purchases.index'))
        ->assertOk()
        ->assertSee(route('purchases.show', $purchase->id));

    $this->get(route('payments.index'))
        ->assertOk()
        ->assertSee(route('payments.show', $payment->id));
});

it('la ruta de la compra no se traga /compras/pagos ni /compras/nueva', function () {
    $resolve = fn (string $uri) => app('router')->getRoutes()
        ->match(Request::create($uri))
        ->getName();

    expect($resolve('/compras/nueva'))->toBe('purchases.create')
        ->and($resolve('/compras/pagos'))->toBe('payments.index')
        ->and($resolve('/compras/antiguedad'))->toBe('payables.aging')
        ->and($resolve('/compras/7'))->toBe('purchases.show')
        ->and($resolve('/compras/pagos/7'))->toBe('payments.show');
});
