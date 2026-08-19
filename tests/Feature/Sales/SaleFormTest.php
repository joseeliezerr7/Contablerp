<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\ProductPrice;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Sales\Models\Sale;
use App\Livewire\Sales\SaleForm;
use Livewire\Livewire;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    // El vendedor es quien factura; el contador no tiene ese permiso.
    $this->user = actingAsUserOf($this->company, role: PermissionCatalog::SALESPERSON);

    $this->branch = mainBranch();
    $this->customer = makeCustomer(['credit_limit' => '50000.00', 'credit_days' => 15]);
    $this->product = makeProduct('250.00');
});

/**
 * El autocompletado por código es la interacción central de la pantalla: si se
 * rompe, el usuario tiene que teclear descripción y precio a mano en cada línea
 * y no habría ningún error visible que lo delate.
 */
it('completa descripción, precio e impuesto al escribir el código del producto', function () {
    Livewire::test(SaleForm::class)
        ->set('customer_id', $this->customer->id)
        ->set('lines.0.product_code', $this->product->code)
        ->assertSet('lines.0.product_id', $this->product->id)
        ->assertSet('lines.0.description', $this->product->name)
        ->assertSet('lines.0.unit_price', '250.0000')
        ->assertSet('lines.0.tax_id', tax()->id);
});

it('encuentra el producto por código de barras', function () {
    $this->product->forceFill(['barcode' => '7501234567890'])->save();

    Livewire::test(SaleForm::class)
        ->set('lines.0.product_code', '7501234567890')
        ->assertSet('lines.0.product_id', $this->product->id);
});

it('avisa cuando el código no existe', function () {
    Livewire::test(SaleForm::class)
        ->set('lines.0.product_code', 'NOEXISTE')
        ->assertHasErrors('lines.0.product_code');
});

it('toma las condiciones de crédito del cliente al seleccionarlo', function () {
    Livewire::test(SaleForm::class)
        ->set('customer_id', $this->customer->id)
        ->assertSet('payment_condition', 'credit')
        ->assertSet('credit_days', 15);
});

it('pasa a contado con un cliente sin crédito', function () {
    $mostrador = makeCustomer(['credit_limit' => '0']);

    Livewire::test(SaleForm::class)
        ->set('customer_id', $mostrador->id)
        ->assertSet('payment_condition', 'cash');
});

it('usa el precio de la lista del cliente', function () {
    $mayorista = priceList('MAY');

    $precio = new ProductPrice;
    $precio->forceFill([
        'company_id' => $this->company->id,
        'product_id' => $this->product->id,
        'price_list_id' => $mayorista->id,
        'price' => '180.00',
    ])->save();

    $cliente = makeCustomer(['price_list_id' => $mayorista->id]);

    Livewire::test(SaleForm::class)
        ->set('customer_id', $cliente->id)
        ->set('lines.0.product_code', $this->product->code)
        ->assertSet('lines.0.unit_price', '180.0000');
});

it('emite la factura desde el formulario', function () {
    Livewire::test(SaleForm::class)
        ->set('customer_id', $this->customer->id)
        ->set('lines.0.product_code', $this->product->code)
        ->set('lines.0.quantity', '4')
        ->call('saveAndIssue')
        ->assertHasNoErrors();

    $sale = Sale::query()->firstOrFail();

    expect($sale->isIssued())->toBeTrue()
        ->and($sale->totalAmount()->toString())->toBe('1150.0000')
        ->and($sale->receivable)->not->toBeNull();
});

it('guarda un borrador desde el formulario', function () {
    Livewire::test(SaleForm::class)
        ->set('customer_id', $this->customer->id)
        ->set('lines.0.product_code', $this->product->code)
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect(Sale::query()->firstOrFail()->isDraft())->toBeTrue();
});

it('muestra el error de negocio sin perder lo capturado', function () {
    $sinCredito = makeCustomer(['credit_limit' => '0']);

    Livewire::test(SaleForm::class)
        ->set('customer_id', $sinCredito->id)
        ->set('payment_condition', 'credit')
        ->set('lines.0.product_code', $this->product->code)
        ->call('saveAndIssue')
        ->assertHasErrors('lines');
});

it('agrega y quita líneas', function () {
    Livewire::test(SaleForm::class)
        ->call('addLine')
        ->assertCount('lines', 2)
        ->call('removeLine', 1)
        ->assertCount('lines', 1)
        // Nunca se queda sin líneas.
        ->call('removeLine', 0)
        ->assertCount('lines', 1);
});
