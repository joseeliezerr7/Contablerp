<?php

declare(strict_types=1);

use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Receivables\Models\Receipt;
use App\Domains\Receivables\Services\ReceiptService;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleService;
use App\Livewire\Sales\ReceiptShow;
use App\Livewire\Sales\SaleShow;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/**
 * Ver una factura y un recibo ya emitidos.
 *
 * El hueco venía del propio diseño: un documento contabilizado es inmutable, así
 * que la pantalla de edición solo sirve borradores. Al emitirlo, el documento
 * desaparecía de la vista. De la factura al menos quedaba el PDF —que es el
 * documento fiscal del cliente, no una pantalla de consulta—; del recibo no
 * quedaba nada.
 */
beforeEach(function () {
    [$this->company, $this->accountant] = accountingCompanyWithAccountant();
    // Con crédito autorizado: sin límite, el servicio obliga a que la venta sea
    // de contado y no habría cuenta por cobrar que mostrar.
    $this->customer = makeCustomer(['credit_limit' => '900000.00', 'credit_days' => 30]);
});

/**
 * Factura a crédito por 1 000 + ISV, que deja cuenta por cobrar.
 */
function detailSale(string $unitPrice = '1000.00'): Sale
{
    return app(SaleService::class)->createAndIssue([
        'branch_id' => mainBranch()->id,
        'customer_id' => test()->customer->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [
        ['product_id' => makeProduct($unitPrice)->id, 'quantity' => '1', 'unit_price' => $unitPrice],
    ]);
}

/**
 * Recibo que abona el monto indicado a la factura dada.
 *
 * `payment_method` no lleva valor por defecto en el servicio: se lee sin
 * coalescencia, así que omitirlo revienta.
 */
function detailReceipt(Sale $sale, string $amount): Receipt
{
    return app(ReceiptService::class)->create([
        'branch_id' => mainBranch()->id,
        'customer_id' => $sale->customer_id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Transfer,
        'deposit_account_id' => account('1.1.02.01')->id,
        'reference' => 'TRF-001',
    ], [
        ['receivable_id' => $sale->receivable->id, 'amount' => $amount],
    ]);
}

/*
|--------------------------------------------------------------------------
| La factura
|--------------------------------------------------------------------------
*/

it('muestra los renglones, los totales y los datos fiscales de la factura', function () {
    $sale = detailSale();

    Livewire::test(SaleShow::class, ['sale' => $sale->id])
        ->assertSee($sale->number)
        ->assertSee($this->customer->name)
        // Los tres elementos que exige el SAR, congelados al emitir.
        ->assertSee($sale->cai)
        ->assertSee($sale->fiscalRangeLabel())
        ->assertSee($sale->fiscal_limit_date->format('d/m/Y'))
        // 1 000 + 15 % = 1 150.
        ->assertSee('1,150.00')
        ->assertSee('Cuenta por cobrar');
});

it('muestra la factura anulada con su motivo, sin dejar editarla', function () {
    $sale = detailSale();

    app(SaleService::class)->void($sale, 'El cliente canceló el pedido');

    Livewire::test(SaleShow::class, ['sale' => $sale->id])
        ->assertSee('Factura anulada')
        ->assertSee('El cliente canceló el pedido')
        // Su número fiscal no se puede reutilizar, así que el documento queda.
        ->assertSee($sale->number);
});

it('enlaza la factura con la partida que generó', function () {
    $sale = detailSale();

    Livewire::test(SaleShow::class, ['sale' => $sale->id])
        ->assertSee($sale->journalEntry()->number)
        ->assertSee('Ver la partida');
});

/*
|--------------------------------------------------------------------------
| El recibo
|--------------------------------------------------------------------------
*/

it('dice contra qué facturas se aplicó el recibo', function () {
    $sale = detailSale();

    $receipt = detailReceipt($sale, '400.00');

    Livewire::test(ReceiptShow::class, ['receipt' => $receipt->id])
        ->assertSee($receipt->number)
        ->assertSee('Facturas que abonó')
        // La factura abonada, cuánto se le aplicó y qué saldo le quedó.
        ->assertSee($sale->receivable->document_number)
        ->assertSee('400.00')
        ->assertSee('750.00');
});

it('el monto del recibo es siempre la suma de lo aplicado', function () {
    // No hay anticipos en el sistema: `ReceiptService::create` fija el monto del
    // encabezado como la suma de sus aplicaciones, así que la pantalla nunca
    // tiene que mostrar una diferencia. Se comprueba para que, si algún día se
    // agregan anticipos, esta prueba obligue a decidir cómo se muestran.
    $sale = detailSale();

    $receipt = detailReceipt($sale, '400.00');

    expect($receipt->amountMoney()->format())->toBe('400.00');

    Livewire::test(ReceiptShow::class, ['receipt' => $receipt->id])
        ->assertDontSee('Descuadre');
});

/*
|--------------------------------------------------------------------------
| Aislamiento y permisos
|--------------------------------------------------------------------------
*/

it('no deja abrir la factura de otra empresa', function () {
    $otra = accountingCompany();

    $ajena = app(CompanyContext::class)->runFor($otra, function (): Sale {
        actingAsUserOf(app(CompanyContext::class)->companyOrFail(), role: PermissionCatalog::ACCOUNTANT);
        test()->customer = makeCustomer(['credit_limit' => '900000.00', 'credit_days' => 30]);

        return detailSale();
    });

    $this->actingAs($this->accountant);
    app(CompanyContext::class)->set($this->company);

    Livewire::test(SaleShow::class, ['sale' => $ajena->id]);
})->throws(ModelNotFoundException::class);

it('no deja abrir el recibo de otra empresa', function () {
    $otra = accountingCompany();

    $ajeno = app(CompanyContext::class)->runFor($otra, function (): Receipt {
        actingAsUserOf(app(CompanyContext::class)->companyOrFail(), role: PermissionCatalog::ACCOUNTANT);
        test()->customer = makeCustomer(['credit_limit' => '900000.00', 'credit_days' => 30]);
        $sale = detailSale();

        return detailReceipt($sale, '100.00');
    });

    $this->actingAs($this->accountant);
    app(CompanyContext::class)->set($this->company);

    Livewire::test(ReceiptShow::class, ['receipt' => $ajeno->id]);
})->throws(ModelNotFoundException::class);

it('le niega la factura a quien no puede ver facturas', function () {
    $sale = detailSale();

    actingAsUserOf($this->company, role: PermissionCatalog::WAREHOUSE);

    $this->get(route('sales.show', $sale->id))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Los enlaces que faltaban
|--------------------------------------------------------------------------
*/

it('ofrece «Ver» en los dos listados', function () {
    $sale = detailSale();

    $receipt = detailReceipt($sale, '100.00');

    $this->get(route('sales.index'))
        ->assertOk()
        ->assertSee(route('sales.show', $sale->id));

    $this->get(route('receipts.index'))
        ->assertOk()
        ->assertSee(route('receipts.show', $receipt->id));
});

it('la ruta de la factura no se traga /ventas/facturas/nueva', function () {
    // Sin `whereNumber`, `{sale}` capturaría «nueva» y la pantalla de crear
    // factura dejaría de existir en cuanto alguien reordenara las rutas.
    //
    // Se comprueba contra el enrutador y no pidiendo la página: el permiso de
    // crear facturas es del vendedor, no del contador, así que un `get` daría
    // 403 y no diría nada sobre qué ruta resolvió.
    $resolve = fn (string $uri) => app('router')->getRoutes()
        ->match(Request::create($uri))
        ->getName();

    expect($resolve('/ventas/facturas/nueva'))->toBe('sales.create')
        ->and($resolve('/ventas/facturas/7'))->toBe('sales.show')
        ->and($resolve('/ventas/facturas/7/editar'))->toBe('sales.edit');
});
