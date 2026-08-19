<?php

declare(strict_types=1);

use App\Domains\Fiscal\Models\FiscalAuthorization;
use App\Domains\Fiscal\Services\DocumentPrinter;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleService;

/**
 * La impresión del documento fiscal.
 *
 * Lo que se comprueba aquí no es que el PDF «se vea bien»: es que lleve los
 * elementos que el régimen exige —CAI, rango autorizado, fecha límite, los dos
 * RTN, el total en letras y la leyenda— y que **salgan de lo congelado en el
 * documento**. Una factura reimpresa con el CAI de hoy es un documento distinto
 * del que se entregó, y eso es exactamente lo que no puede pasar.
 */
beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->sales = app(SaleService::class);
    $this->printer = app(DocumentPrinter::class);
    $this->branch = mainBranch();
    $this->cashAccount = account('1.1.02.01');
});

function issuedInvoice(object $ctx, string $price = '1000.00'): Sale
{
    $customer = makeCustomer(['name' => 'Ferretería El Cliente', 'tax_id' => '08019099887766']);
    $product = makeProduct($price);

    return $ctx->sales->createAndIssue([
        'branch_id' => $ctx->branch->id,
        'customer_id' => $customer->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Cash,
        'deposit_account_id' => $ctx->cashAccount->id,
    ], [
        ['product_id' => $product->id, 'quantity' => '2', 'unit_price' => $price, 'tax_id' => tax()->id],
    ]);
}

it('imprime los elementos que exige el régimen', function () {
    $sale = issuedInvoice($this);
    $authorization = FiscalAuthorization::query()->firstOrFail();

    $html = $this->printer->render($sale, 'invoice', 'Factura');

    expect($html)
        ->toContain($sale->number)
        ->toContain($authorization->cai)
        // Rango autorizado impreso completo, no solo los correlativos sueltos.
        ->toContain('000-001-01-00000001 al 000-001-01-00005000')
        ->toContain($sale->fiscal_limit_date->format('d/m/Y'))
        // Los dos RTN: emisor y receptor.
        ->toContain($this->company->tax_id)
        ->toContain('08019099887766')
        // Total en letras y la leyenda obligatoria. 2 × 1 000 + 15 % = 2 300.
        ->toContain('DOS MIL TRESCIENTOS LEMPIRAS CON 00/100')
        ->toContain('LA FACTURA ES BENEFICIO DE TODOS, EXÍJALA');
});

it('reimprime con el CAI que llevaba, no con el vigente hoy', function () {
    $sale = issuedInvoice($this);
    $caiOriginal = $sale->cai;

    // Llega una autorización nueva: la anterior queda reemplazada.
    withFiscalAuthorization($this->company, overrides: [
        'cai' => 'NUEVOCAI-111111-222222-333333-444444-99',
        'range_from' => 5001,
        'range_to' => 9000,
    ]);

    $html = $this->printer->render($sale->refresh(), 'invoice', 'Factura');

    expect($html)->toContain($caiOriginal)
        ->and($html)->not->toContain('NUEVOCAI')
        ->and($html)->toContain('000-001-01-00000001 al 000-001-01-00005000');
});

it('separa el importe gravado del exento y desglosa el impuesto', function () {
    $customer = makeCustomer();
    $gravado = makeProduct('100.00');
    $exento = makeProduct('50.00');

    // Un producto sin impuesto: su base va al renglón de exentos.
    $exento->forceFill(['tax_id' => null])->save();

    $sale = $this->sales->createAndIssue([
        'branch_id' => $this->branch->id,
        'customer_id' => $customer->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Cash,
        'deposit_account_id' => $this->cashAccount->id,
    ], [
        ['product_id' => $gravado->id, 'quantity' => '1', 'unit_price' => '100.00', 'tax_id' => tax()->id],
        ['product_id' => $exento->id, 'quantity' => '1', 'unit_price' => '50.00'],
    ]);

    $html = $this->printer->render($sale, 'invoice', 'Factura');

    expect($html)->toContain('Importe gravado')
        ->toContain('Importe exento')
        // El desglose nombra el impuesto con su tasa, para poder comprobar la
        // cuenta. Y la nombra **una sola vez**: «ISV 15%», no «ISV 15% 15 %».
        ->toContain('ISV 15%')
        ->not->toContain('ISV 15% 15 %');
});

it('marca en el papel que el documento está anulado', function () {
    $sale = issuedInvoice($this);
    $this->sales->void($sale, 'Error en el precio');

    $html = $this->printer->render($sale->refresh(), 'invoice', 'Factura');

    expect($html)->toContain('DOCUMENTO ANULADO');
});

/*
|--------------------------------------------------------------------------
| Acceso
|--------------------------------------------------------------------------
*/

it('descarga el PDF de una factura emitida', function () {
    $sale = issuedInvoice($this);

    $response = $this->get(route('sales.print', $sale->id));

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('no imprime un borrador', function () {
    $customer = makeCustomer();
    $product = makeProduct();

    $draft = $this->sales->saveDraft([
        'branch_id' => $this->branch->id,
        'customer_id' => $customer->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Cash,
        'deposit_account_id' => $this->cashAccount->id,
    ], [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '100.00']]);

    // Un borrador no tiene número fiscal: imprimirlo produciría un papel con
    // pinta de factura y sin CAI.
    $this->get(route('sales.print', $draft->id))->assertForbidden();
});

it('le niega la impresión a quien no tiene el permiso', function () {
    $sale = issuedInvoice($this);

    // El bodeguero no ve documentos comerciales.
    actingAsUserOf($this->company, role: PermissionCatalog::WAREHOUSE);

    $this->get(route('sales.print', $sale->id))->assertForbidden();
});

it('no imprime la factura de otra empresa', function () {
    $sale = issuedInvoice($this);

    $otra = accountingCompany();
    actingAsUserOf($otra, role: PermissionCatalog::ACCOUNTANT);

    $this->get(route('sales.print', $sale->id))->assertNotFound();
});
