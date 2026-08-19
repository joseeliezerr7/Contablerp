<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Product;
use App\Domains\Fiscal\Enums\FiscalDocumentType;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Inventory\Models\StockAdjustment;
use App\Domains\Inventory\Services\StockAdjustmentService;
use App\Domains\Inventory\Services\StockTransferService;
use App\Domains\Payables\Services\PaymentService;
use App\Domains\Purchases\Services\PurchaseService;
use App\Domains\Sales\Enums\CreditNoteReason;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Models\CreditNote;
use App\Domains\Sales\Services\CreditNoteService;
use App\Domains\Sales\Services\SaleService;
use App\Domains\Tenancy\Models\Warehouse;
use App\Domains\Treasury\Models\Check;
use App\Domains\Treasury\Services\BankAccountService;
use App\Livewire\Inventory\AdjustmentShow;
use App\Livewire\Inventory\TransferShow;
use App\Livewire\Sales\CreditNoteShow;
use App\Livewire\Treasury\CheckShow;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Livewire\Livewire;

/**
 * Los últimos cuatro detalles de documento: ajuste, traslado, cheque y nota de
 * crédito.
 *
 * Con estos se cierra el hueco estructural: **todo documento que el sistema
 * emite se puede abrir y leer**, no solo anular. La nota de crédito era la menos
 * urgente —tiene PDF—, pero el PDF es el documento fiscal del cliente; la
 * pantalla enseña además la factura que acredita y el efecto sobre su saldo,
 * que el papel no trae.
 */
beforeEach(function () {
    $this->company = accountingCompany();
    $this->accountant = actingAsUserOf($this->company, role: PermissionCatalog::ACCOUNTANT);
});

/**
 * Deja existencias: compra simple ya recibida, para que las salidas de
 * inventario tengan de dónde descargar.
 */
function opStock(string $quantity = '100'): Product
{
    $product = makeProduct('100.00', tracked: true);

    app(PurchaseService::class)->createAndReceive([
        'branch_id' => mainBranch()->id,
        'warehouse_id' => warehouse()->id,
        'supplier_id' => makeSupplier()->id,
        'supplier_invoice_number' => '000-001-01-0000'.random_int(1000, 9999),
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [
        ['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => '60.00'],
    ]);

    return $product;
}

/*
|--------------------------------------------------------------------------
| Ajuste de inventario
|--------------------------------------------------------------------------
*/

it('muestra el ajuste con sus renglones y el efecto en el inventario', function () {
    $product = opStock();

    // Faltaron 10 en el conteo: el inventario baja 10 × 69 (60 + ISV al costo).
    $adjustment = app(StockAdjustmentService::class)->createAndPost([
        'branch_id' => mainBranch()->id,
        'warehouse_id' => warehouse()->id,
        'date' => now()->toDateString(),
        'reason' => 'count',
    ], [
        ['product_id' => $product->id, 'quantity' => '-10'],
    ]);

    Livewire::test(AdjustmentShow::class, ['adjustment' => $adjustment->id])
        ->assertSee($adjustment->number)
        ->assertSee('el inventario bajó')
        ->assertSee($product->name)
        ->assertSee($adjustment->journalEntry()->number);
});

/*
|--------------------------------------------------------------------------
| Traslado
|--------------------------------------------------------------------------
*/

it('muestra el traslado y explica por qué no lleva partida', function () {
    $product = opStock();

    $destino = Warehouse::query()->create([
        'branch_id' => mainBranch()->id,
        'code' => 'BOD2',
        'name' => 'Bodega secundaria',
        'is_active' => true,
    ]);

    $transfer = app(StockTransferService::class)->createAndPost([
        'branch_id' => mainBranch()->id,
        'from_warehouse_id' => warehouse()->id,
        'to_warehouse_id' => $destino->id,
        'date' => now()->toDateString(),
    ], [
        ['product_id' => $product->id, 'quantity' => '5'],
    ]);

    Livewire::test(TransferShow::class, ['transfer' => $transfer->id])
        ->assertSee($transfer->number)
        ->assertSee('Bodega secundaria')
        // La aclaración que evita la consulta de siempre: no hay asiento porque
        // la mercadería no cambió de valor ni de dueño, solo de estante.
        ->assertSee('no genera partida contable');
});

/*
|--------------------------------------------------------------------------
| Cheque
|--------------------------------------------------------------------------
*/

/**
 * Un pago con cheque deja el cheque creado por el propio servicio, enlazado por
 * source_type/source_id.
 *
 * Necesita una cuenta bancaria **con chequera** sobre la cuenta contable del
 * pago: sin `next_check_number`, `issuesChecks()` es false y el servicio se
 * limita a anotar la referencia a mano.
 */
function opCheck(object $ctx): Check
{
    app(BankAccountService::class)->create([
        'account_id' => account('1.1.02.01')->id,
        'bank_name' => 'Banco Atlántida',
        'number' => '01-234-567890',
        'alias' => 'Cuenta operativa',
        'next_check_number' => 1001,
    ]);

    $purchase = app(PurchaseService::class)->createAndReceive([
        'branch_id' => mainBranch()->id,
        'warehouse_id' => warehouse()->id,
        'supplier_id' => makeSupplier()->id,
        'supplier_invoice_number' => '000-001-01-00007777',
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [
        ['product_id' => makeProduct('100.00')->id, 'quantity' => '10', 'unit_price' => '100.00'],
    ]);

    app(PaymentService::class)->create([
        'branch_id' => mainBranch()->id,
        'supplier_id' => $purchase->supplier_id,
        'date' => now()->toDateString(),
        'payment_method' => PaymentMethod::Check,
        'payment_account_id' => account('1.1.02.01')->id,
    ], [
        ['payable_id' => $purchase->payable->id, 'amount' => '500.00'],
    ]);

    return Check::query()->firstOrFail();
}

it('muestra el cheque con su recorrido y el pago del que nació', function () {
    $check = opCheck($this);

    Livewire::test(CheckShow::class, ['check' => $check->id])
        ->assertSee($check->number)
        ->assertSee($check->payee)
        ->assertSee('Recorrido')
        // El enlace de vuelta al pago que lo originó.
        ->assertSee('Este cheque paga el documento');
});

/*
|--------------------------------------------------------------------------
| Nota de crédito
|--------------------------------------------------------------------------
*/

/**
 * Factura a crédito y nota que devuelve parte de la mercadería.
 *
 * La nota lleva su propia autorización del SAR (tipo 03), que la empresa de
 * prueba no trae: se registra aquí.
 */
function opCreditNote(object $ctx): CreditNote
{
    withFiscalAuthorization($ctx->company, FiscalDocumentType::CreditNote);

    $customer = makeCustomer(['credit_limit' => '900000.00', 'credit_days' => 30]);
    $product = opStock();

    $sale = app(SaleService::class)->createAndIssue([
        'branch_id' => mainBranch()->id,
        // La factura despacha mercadería con control de existencias: sin
        // bodega, el servicio la rechaza.
        'warehouse_id' => warehouse()->id,
        'customer_id' => $customer->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [
        ['product_id' => $product->id, 'quantity' => '10', 'unit_price' => '100.00'],
    ]);

    $service = app(CreditNoteService::class);

    return $service->issue($service->saveDraft($sale, [
        'date' => now()->toDateString(),
        'reason' => CreditNoteReason::Return,
        'description' => 'El cliente devolvió dos unidades dañadas',
        'warehouse_id' => warehouse()->id,
    ], [
        ['sale_item_id' => $sale->items->first()->id, 'quantity' => '2'],
    ]));
}

it('muestra la nota con su CAI, la factura que acredita y el efecto en el saldo', function () {
    $note = opCreditNote($this);

    Livewire::test(CreditNoteShow::class, ['creditNote' => $note->id])
        ->assertSee($note->number)
        // Su propia autorización, distinta a la de la factura.
        ->assertSee($note->cai)
        ->assertSee($note->sale->number)
        ->assertSee('El cliente devolvió dos unidades dañadas')
        ->assertSee('Efecto en la cuenta por cobrar')
        // 10 × 115 = 1 150 original; 2 × 115 = 230 acreditado; saldo 920.
        ->assertSee('230.00')
        ->assertSee('920.00');
});

/*
|--------------------------------------------------------------------------
| Aislamiento y rutas
|--------------------------------------------------------------------------
*/

it('no abre el ajuste de otra empresa', function () {
    $otra = accountingCompany();

    $ajeno = app(CompanyContext::class)->runFor($otra, function (): StockAdjustment {
        actingAsUserOf(app(CompanyContext::class)->companyOrFail(), role: PermissionCatalog::ACCOUNTANT);
        $product = opStock();

        return app(StockAdjustmentService::class)->createAndPost([
            'branch_id' => mainBranch()->id,
            'warehouse_id' => warehouse()->id,
            'date' => now()->toDateString(),
            'reason' => 'count',
        ], [
            ['product_id' => $product->id, 'quantity' => '-1'],
        ]);
    });

    $this->actingAs($this->accountant);
    app(CompanyContext::class)->set($this->company);

    Livewire::test(AdjustmentShow::class, ['adjustment' => $ajeno->id]);
})->throws(ModelNotFoundException::class);

it('le niega el cheque a quien no ve tesorería', function () {
    $check = opCheck($this);

    actingAsUserOf($this->company, role: PermissionCatalog::SALESPERSON);

    $this->get(route('treasury.checks.show', $check->id))->assertForbidden();
});

it('las rutas nuevas no se tragan las de crear', function () {
    $resolve = fn (string $uri) => app('router')->getRoutes()
        ->match(Request::create($uri))
        ->getName();

    expect($resolve('/inventario/ajustes/nuevo'))->toBe('inventory.adjustments.create')
        ->and($resolve('/inventario/ajustes/7'))->toBe('inventory.adjustments.show')
        ->and($resolve('/inventario/traslados/nuevo'))->toBe('inventory.transfers.create')
        ->and($resolve('/inventario/traslados/7'))->toBe('inventory.transfers.show')
        ->and($resolve('/ventas/notas-credito/nueva'))->toBe('credit-notes.create')
        ->and($resolve('/ventas/notas-credito/7'))->toBe('credit-notes.show')
        ->and($resolve('/tesoreria/cheques/7'))->toBe('treasury.checks.show');
});
