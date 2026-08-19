<?php

declare(strict_types=1);

use App\Domains\Fiscal\Enums\FiscalDocumentType;
use App\Domains\Fiscal\Models\FiscalAuthorization;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Sales\Enums\CreditNoteReason;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Models\CreditNote;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\CreditNoteService;
use App\Domains\Sales\Services\SaleService;
use App\Livewire\Sales\CreditNoteForm;
use App\Livewire\Sales\CreditNoteIndex;
use Livewire\Livewire;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->sales = app(SaleService::class);
    $this->service = app(CreditNoteService::class);
    $this->branch = mainBranch();

    withFiscalAuthorization($this->company, FiscalDocumentType::CreditNote);
});

function simpleInvoice(object $ctx): Sale
{
    return $ctx->sales->createAndIssue([
        'branch_id' => $ctx->branch->id,
        'customer_id' => makeCustomer()->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Cash,
        'deposit_account_id' => account('1.1.02.01')->id,
    ], [['product_id' => makeProduct('500.00')->id, 'quantity' => '4', 'unit_price' => '500.00', 'tax_id' => tax()->id]]);
}

/*
|--------------------------------------------------------------------------
| Captura
|--------------------------------------------------------------------------
*/

it('trae las líneas de la factura al buscarla por su número', function () {
    $sale = simpleInvoice($this);

    Livewire::test(CreditNoteForm::class)
        ->set('saleNumber', $sale->number)
        ->call('findSale')
        ->assertHasNoErrors()
        ->assertSet('saleId', $sale->id)
        ->assertCount('lines', 1)
        ->assertSee($sale->items->first()->description);
});

it('avisa si el número de factura no existe', function () {
    Livewire::test(CreditNoteForm::class)
        ->set('saleNumber', '000-001-01-00099999')
        ->call('findSale')
        ->assertHasErrors('saleNumber');
});

it('calcula el total a acreditar en el servidor, con impuesto', function () {
    $sale = simpleInvoice($this);

    // 2 de 4 unidades de 500 = 1 000 + 15 % = 1 150.
    Livewire::test(CreditNoteForm::class)
        ->set('saleNumber', $sale->number)
        ->call('findSale')
        ->set('lines.0.quantity', '2')
        ->assertSee('1,150.00');
});

it('guarda el borrador con las líneas que llevan cantidad', function () {
    $sale = simpleInvoice($this);

    Livewire::test(CreditNoteForm::class)
        ->set('saleNumber', $sale->number)
        ->call('findSale')
        ->set('date', now()->toDateString())
        ->set('reason', CreditNoteReason::Return->value)
        ->set('description', 'El cliente devolvió dos unidades')
        ->set('lines.0.quantity', '2')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('credit-notes.index'));

    $note = CreditNote::query()->firstOrFail();

    expect($note->status)->toBe(SaleStatus::Draft)
        ->and($note->items)->toHaveCount(1)
        ->and($note->number)->toBeNull();
});

it('no guarda sin cantidades', function () {
    $sale = simpleInvoice($this);

    Livewire::test(CreditNoteForm::class)
        ->set('saleNumber', $sale->number)
        ->call('findSale')
        ->set('description', 'Nota vacía que no debería guardarse')
        ->call('save')
        ->assertHasErrors('lines');

    expect(CreditNote::query()->count())->toBe(0);
});

it('avisa que falta el CAI de nota de crédito antes de capturar', function () {
    $sale = simpleInvoice($this);

    FiscalAuthorization::query()
        ->where('document_type', FiscalDocumentType::CreditNote)
        ->delete();

    Livewire::test(CreditNoteForm::class)
        ->set('saleNumber', $sale->number)
        ->call('findSale')
        ->assertSee('no tiene una autorización vigente');
});

/*
|--------------------------------------------------------------------------
| Emisión y anulación desde la lista
|--------------------------------------------------------------------------
*/

it('emite el borrador desde la lista', function () {
    $sale = simpleInvoice($this);

    $note = $this->service->saveDraft($sale, [
        'reason' => CreditNoteReason::Return,
        'description' => 'Devolución de una unidad',
    ], [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1']]);

    Livewire::test(CreditNoteIndex::class)
        ->call('confirmIssue', $note->id)
        ->call('issue')
        ->assertHasNoErrors();

    expect($note->refresh()->status)->toBe(SaleStatus::Issued)
        ->and($note->number)->toBe('000-001-03-00000001');
});

it('explica en el diálogo por qué no se puede emitir', function () {
    $sale = simpleInvoice($this);

    $note = $this->service->saveDraft($sale, [
        'reason' => CreditNoteReason::Return,
        'description' => 'Devolución de una unidad',
    ], [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1']]);

    // Se agota el CAI de notas de crédito justo antes de emitir.
    FiscalAuthorization::query()
        ->where('document_type', FiscalDocumentType::CreditNote)
        ->delete();

    Livewire::test(CreditNoteIndex::class)
        ->call('confirmIssue', $note->id)
        ->call('issue')
        ->assertHasErrors('issuing');

    expect($note->refresh()->status)->toBe(SaleStatus::Draft);
});

it('exige motivo para anular', function () {
    $sale = simpleInvoice($this);

    $note = $this->service->issue($this->service->saveDraft($sale, [
        'reason' => CreditNoteReason::Return,
        'description' => 'Devolución de una unidad',
    ], [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1']]));

    Livewire::test(CreditNoteIndex::class)
        ->call('confirmVoid', $note->id)
        ->call('void')
        ->assertHasErrors(['voidReason' => 'required'])
        ->set('voidReason', 'La devolución no procedía')
        ->call('void')
        ->assertHasNoErrors();

    expect($note->refresh()->status)->toBe(SaleStatus::Voided);
});

/*
|--------------------------------------------------------------------------
| Permisos
|--------------------------------------------------------------------------
*/

it('deja capturar al vendedor pero no emitir', function () {
    $sale = simpleInvoice($this);

    $note = $this->service->saveDraft($sale, [
        'reason' => CreditNoteReason::Return,
        'description' => 'Devolución recibida en el mostrador',
    ], [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1']]);

    // Quien recibe la devolución la captura; acreditar rebaja el ingreso
    // declarado y esa firma es del contador.
    actingAsUserOf($this->company, role: PermissionCatalog::SALESPERSON);

    // Puede abrir la captura…
    Livewire::test(CreditNoteForm::class)->assertSet('noteId', null);

    // …pero no emitir.
    Livewire::test(CreditNoteIndex::class)
        ->call('confirmIssue', $note->id)
        ->assertForbidden();
});

it('le niega la lista al bodeguero', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::WAREHOUSE);

    $this->get(route('credit-notes.index'))->assertForbidden();
});
