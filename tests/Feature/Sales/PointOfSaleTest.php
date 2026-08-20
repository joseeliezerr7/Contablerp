<?php

declare(strict_types=1);

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Fiscal\Models\FiscalAuthorization;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Partners\Models\Customer;
use App\Domains\Purchases\Services\PurchaseService;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\PointOfSaleService;
use App\Domains\Treasury\Models\CashSession;
use App\Domains\Treasury\Services\CashSessionService;
use App\Livewire\Sales\PointOfSale;
use App\Support\Money;
use Livewire\Livewire;

/**
 * Punto de venta de mostrador.
 *
 * Lo que se prueba aquí no es una pantalla: es que una venta de mostrador sea
 * una factura de verdad —con su CAI, su kardex y su partida— y que el efectivo
 * caiga en la caja que está abierta, que es lo único que hace que el arqueo del
 * cierre signifique algo.
 */
beforeEach(function () {
    [$this->company] = accountingCompanyWithAccountant();

    $this->pos = app(PointOfSaleService::class);
    $this->branch = mainBranch();
    $this->warehouse = warehouse();
    $this->till = account('1.1.01.01');

    // El mostrador factura a este cuando el cliente no se identifica.
    makeCustomer(['name' => 'Cliente de mostrador', 'is_walk_in' => true]);

    // El mostrador es del cajero, no del contador: el contador puede anular una
    // factura pero no emitirla, y esa segregación es deliberada.
    $this->user = actingAsUserOf($this->company, role: PermissionCatalog::CASHIER);
});

/**
 * Abre una caja para el usuario autenticado y devuelve la sesión.
 */
function openPosTill(object $ctx): CashSession
{
    return app(CashSessionService::class)->open([
        'branch_id' => $ctx->branch->id,
        'account_id' => $ctx->till->id,
        'opening_float' => '500.00',
    ]);
}

/**
 * Producto con existencia comprada, para que el kardex tenga costo real.
 */
function posProduct(object $ctx, string $price = '100.00', string $barcode = '7501234567890')
{
    $product = makeProduct($price, tracked: true);
    $product->forceFill(['barcode' => $barcode])->save();

    app(PurchaseService::class)->createAndReceive([
        'branch_id' => $ctx->branch->id,
        'warehouse_id' => $ctx->warehouse->id,
        'supplier_id' => makeSupplier()->id,
        'supplier_invoice_number' => 'C-'.$product->id,
        'date' => now()->subDay()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $product->id, 'quantity' => '100', 'unit_price' => '60.00']]);

    return $product->refresh();
}

/*
|--------------------------------------------------------------------------
| La venta
|--------------------------------------------------------------------------
*/

it('emite una factura con CAI desde el mostrador', function () {
    openPosTill($this);
    $product = posProduct($this);

    $sale = $this->pos->checkout($this->branch, [
        ['product_id' => $product->id, 'quantity' => '2', 'unit_price' => '100.00', 'tax_id' => tax()->id],
    ], [
        ['method' => PaymentMethod::Cash, 'amount' => '230.00', 'tendered' => '500.00', 'change_given' => '270.00'],
    ]);

    expect($sale->status)->toBe(SaleStatus::Issued)
        ->and($sale->number)->toBe('000-001-01-00000001')
        ->and($sale->cai)->not->toBeNull()
        ->and($sale->totalAmount()->toString())->toBe(Money::of('230.00')->toString());
});

it('mete el efectivo en la cuenta de la caja abierta', function () {
    $session = openPosTill($this);
    $product = posProduct($this);

    $sale = $this->pos->checkout($this->branch, [
        ['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '100.00', 'tax_id' => tax()->id],
    ], [
        ['method' => PaymentMethod::Cash, 'amount' => '115.00'],
    ]);

    $payment = $sale->payments()->firstOrFail();

    // La cuenta no la eligió nadie: es la de la sesión abierta, y es lo que
    // permite que el arqueo del cierre encuentre este dinero.
    expect($payment->account_id)->toBe($session->account_id)
        ->and($payment->account_id)->toBe($this->till->id);

    $entry = $sale->journalEntry();
    $debitada = $entry->load('lines')->lines
        ->firstWhere('account_id', $this->till->id);

    expect($debitada)->not->toBeNull()
        ->and(Money::of($debitada->debit)->toString())->toBe(Money::of('115.00')->toString());
});

it('el arqueo del cierre encuentra la venta del mostrador', function () {
    $session = openPosTill($this);
    $product = posProduct($this);

    $this->pos->checkout($this->branch, [
        ['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '100.00', 'tax_id' => tax()->id],
    ], [
        ['method' => PaymentMethod::Cash, 'amount' => '115.00'],
    ]);

    // Fondo 500 + venta 115 = 615. Se cuenta exactamente eso y no hay diferencia.
    $cerrada = app(CashSessionService::class)->close($session, Money::of('615.00'));

    expect(Money::of($cerrada->expected_amount)->toString())->toBe(Money::of('615.00')->toString())
        ->and(Money::of($cerrada->difference)->isZero())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Pago dividido
|--------------------------------------------------------------------------
*/

it('reparte el cobro entre efectivo y tarjeta en cuentas distintas', function () {
    $session = openPosTill($this);
    $product = posProduct($this);
    $banco = account('1.1.02.01');

    $sale = $this->pos->checkout($this->branch, [
        ['product_id' => $product->id, 'quantity' => '2', 'unit_price' => '100.00', 'tax_id' => tax()->id],
    ], [
        ['method' => PaymentMethod::Cash, 'amount' => '100.00'],
        ['method' => PaymentMethod::Card, 'amount' => '130.00', 'account_id' => $banco->id, 'reference' => 'VOU-99'],
    ]);

    expect($sale->payments)->toHaveCount(2);

    $entry = $sale->journalEntry()->load('lines');

    $enCaja = $entry->lines->firstWhere('account_id', $session->account_id);
    $enBanco = $entry->lines->firstWhere('account_id', $banco->id);

    // Cada lempira en su cuenta: si los 130 de la tarjeta cayeran en la caja,
    // el arqueo mostraría un sobrante que nadie puede contar.
    expect(Money::of($enCaja->debit)->toString())->toBe(Money::of('100.00')->toString())
        ->and(Money::of($enBanco->debit)->toString())->toBe(Money::of('130.00')->toString())
        ->and($entry->isBalanced())->toBeTrue();
});

it('no acepta cobros que no suman el total', function () {
    openPosTill($this);
    $product = posProduct($this);

    $this->pos->checkout($this->branch, [
        ['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '100.00', 'tax_id' => tax()->id],
    ], [
        ['method' => PaymentMethod::Cash, 'amount' => '100.00'],
    ]);
})->throws(SalesException::class, 'tienen que coincidir exactamente');

it('exige referencia en los medios que la conciliación necesita', function () {
    openPosTill($this);
    $product = posProduct($this);

    $this->pos->checkout($this->branch, [
        ['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '100.00', 'tax_id' => tax()->id],
    ], [
        ['method' => PaymentMethod::Transfer, 'amount' => '115.00', 'account_id' => account('1.1.02.01')->id],
    ]);
})->throws(SalesException::class, 'número de referencia');

/*
|--------------------------------------------------------------------------
| Guardas
|--------------------------------------------------------------------------
*/

it('no vende sin caja abierta', function () {
    $product = posProduct($this);

    $this->pos->checkout($this->branch, [
        ['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '100.00'],
    ], [
        ['method' => PaymentMethod::Cash, 'amount' => '100.00'],
    ]);
})->throws(SalesException::class, 'No tenés una caja abierta');

it('no vende con la caja de otro cajero', function () {
    openPosTill($this);

    // Otro usuario en la misma sucursal: no ve la caja del primero.
    actingAsUserOf($this->company, role: PermissionCatalog::CASHIER);

    expect($this->pos->openSessionFor($this->branch))->toBeNull()
        ->and($this->pos->blockingReason($this->branch))->toContain('No tenés una caja abierta');
});

it('avisa del CAI agotado antes de que el cajero marque nada', function () {
    openPosTill($this);

    FiscalAuthorization::query()->update(['status' => 'exhausted']);

    expect($this->pos->blockingReason($this->branch))->toContain('Se agotó el rango autorizado');
});

/*
|--------------------------------------------------------------------------
| Búsqueda
|--------------------------------------------------------------------------
*/

it('factura al cliente marcado como de mostrador, no al primero de la lista', function () {
    openPosTill($this);
    $product = posProduct($this);

    // Un cliente corporativo creado antes: sin la marca, el mostrador le
    // facturaba a él todo lo que pasaba por la caja.
    $corporativo = makeCustomer(['name' => 'Constructora del Atlántico, S.A.']);

    $sale = $this->pos->checkout($this->branch, [
        ['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '100.00', 'tax_id' => tax()->id],
    ], [
        ['method' => PaymentMethod::Cash, 'amount' => '115.00'],
    ]);

    expect($sale->customer->name)->toBe('Cliente de mostrador')
        ->and($sale->customer_id)->not->toBe($corporativo->id);
});

it('dice qué falta si nadie marcó al cliente de mostrador', function () {
    openPosTill($this);
    Customer::query()->update(['is_walk_in' => false]);

    $this->pos->walkInCustomer();
})->throws(SalesException::class, 'marcado como «de mostrador»');

it('encuentra por código de barras exacto y no por parecido', function () {
    openPosTill($this);
    posProduct($this, barcode: '7501234567890');
    posProduct($this, barcode: '7501234567891');

    $encontrados = $this->pos->search('7501234567890');

    expect($encontrados)->toHaveCount(1)
        ->and($encontrados->first()->barcode)->toBe('7501234567890');
});

/*
|--------------------------------------------------------------------------
| Pantalla
|--------------------------------------------------------------------------
*/

it('busca lo que hay en el campo, no lo que alcanzó a sincronizar', function () {
    openPosTill($this);
    $product = posProduct($this, barcode: '7509999000111');

    // La pistola escribe el código y manda Enter antes de que el retardo del
    // buscador sincronice la propiedad. Si se buscara `term`, la primera venta
    // del día nunca encontraría nada.
    Livewire::test(PointOfSale::class)
        ->call('submitTerm', '7509999000111')
        ->assertHasNoErrors()
        ->assertCount('lines', 1)
        ->assertSet('lines.0.product_id', $product->id);
});

it('suma cantidad al pasar dos veces el mismo producto', function () {
    openPosTill($this);
    $product = posProduct($this);

    Livewire::test(PointOfSale::class)
        ->set('term', $product->barcode)
        ->call('submitTerm')
        ->set('term', $product->barcode)
        ->call('submitTerm')
        // Una sola línea con cantidad 2, no dos líneas de 1.
        ->assertCount('lines', 1)
        ->assertSet('lines.0.quantity', '2');
});

it('calcula el total y el vuelto en el servidor', function () {
    openPosTill($this);
    $product = posProduct($this);

    Livewire::test(PointOfSale::class)
        ->set('term', $product->barcode)
        ->call('submitTerm')
        ->set('lines.0.quantity', '2')
        ->call('startCheckout')
        // 2 × 100 + 15 % = 230.
        ->assertSet('payments.0.amount', '230.0000')
        ->set('tendered', '500')
        ->assertSee('270.00');
});

it('emite desde la pantalla y deja lista la siguiente venta', function () {
    openPosTill($this);
    $product = posProduct($this);

    $componente = Livewire::test(PointOfSale::class)
        ->set('term', $product->barcode)
        ->call('submitTerm')
        ->call('startCheckout')
        ->set('tendered', '200')
        ->call('checkout')
        ->assertHasNoErrors();

    $sale = Sale::query()->whereNotNull('number')->firstOrFail();

    // El mostrador queda limpio para el cliente siguiente.
    $componente->assertSet('lines', [])
        ->assertSet('checkingOut', false)
        ->assertSet('lastSaleId', $sale->id);

    expect($sale->payments)->toHaveCount(1)
        ->and($sale->payments->first()->changeMoney()->toString())
        ->toBe(Money::of('85.00')->toString());
});

it('no deja emitir si los cobros no cubren el total', function () {
    openPosTill($this);
    $product = posProduct($this);

    Livewire::test(PointOfSale::class)
        ->set('term', $product->barcode)
        ->call('submitTerm')
        ->call('startCheckout')
        ->set('payments.0.amount', '50.00')
        ->call('checkout')
        ->assertHasErrors('checkout');

    expect(Sale::query()->whereNotNull('number')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Permisos
|--------------------------------------------------------------------------
*/

it('deja al cajero facturar en el mostrador', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::CASHIER);

    // El cajero factura: quien cobra en el mostrador es quien emite. Lo que
    // sigue sin poder es anular.
    $this->get(route('pos'))->assertOk();
});

it('le niega el mostrador al bodeguero', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::WAREHOUSE);

    $this->get(route('pos'))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Contabilidad
|--------------------------------------------------------------------------
*/

it('deja una sola partida cuadrada por venta', function () {
    openPosTill($this);
    $product = posProduct($this);

    $sale = $this->pos->checkout($this->branch, [
        ['product_id' => $product->id, 'quantity' => '3', 'unit_price' => '100.00', 'tax_id' => tax()->id],
    ], [
        ['method' => PaymentMethod::Cash, 'amount' => '345.00'],
    ]);

    $entries = JournalEntry::query()
        ->where('source_type', Sale::SOURCE_TYPE)
        ->where('source_id', $sale->id)
        ->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->isBalanced())->toBeTrue();
});
