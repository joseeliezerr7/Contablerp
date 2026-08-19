<?php

declare(strict_types=1);

use App\Domains\Fiscal\Enums\AuthorizationStatus;
use App\Domains\Fiscal\Enums\FiscalDocumentType;
use App\Domains\Fiscal\Models\FiscalAuthorization;
use App\Domains\Fiscal\Models\FiscalPoint;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Services\SaleService;
use App\Livewire\Fiscal\FiscalPointIndex;
use Livewire\Livewire;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->branch = mainBranch();
    $this->point = FiscalPoint::query()->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| Puntos de emisión
|--------------------------------------------------------------------------
*/

it('crea un punto de emisión con los códigos del SAR', function () {
    Livewire::test(FiscalPointIndex::class)
        ->call('newPoint')
        ->set('branch_id', $this->branch->id)
        ->set('establishment_code', '002')
        ->set('emission_point_code', '003')
        ->set('name', 'Caja 2')
        ->call('savePoint')
        ->assertHasNoErrors();

    expect(FiscalPoint::query()->where('emission_point_code', '003')->exists())->toBeTrue();
});

it('exige tres dígitos exactos en los códigos', function () {
    // '1' no es '001': los ceros a la izquierda son parte del código.
    Livewire::test(FiscalPointIndex::class)
        ->call('newPoint')
        ->set('branch_id', $this->branch->id)
        ->set('establishment_code', '1')
        ->set('emission_point_code', '0012')
        ->set('name', 'Caja mal capturada')
        ->call('savePoint')
        ->assertHasErrors(['establishment_code', 'emission_point_code']);
});

it('no deja repetir establecimiento y punto', function () {
    Livewire::test(FiscalPointIndex::class)
        ->call('newPoint')
        ->set('branch_id', $this->branch->id)
        ->set('establishment_code', $this->point->establishment_code)
        ->set('emission_point_code', $this->point->emission_point_code)
        ->set('name', 'Duplicado')
        ->call('savePoint')
        ->assertHasErrors('emission_point_code');
});

/*
|--------------------------------------------------------------------------
| Autorizaciones
|--------------------------------------------------------------------------
*/

it('carga una autorización y deja fuera de uso a la anterior', function () {
    $anterior = FiscalAuthorization::query()->firstOrFail();

    Livewire::test(FiscalPointIndex::class)
        ->call('newAuthorization', $this->point->id)
        ->set('document_type', 'invoice')
        ->set('document_type_code', '01')
        ->set('cai', 'AAAAAA-BBBBBB-CCCCCC-DDDDDD-EEEEEE-77')
        ->set('range_from', '5001')
        ->set('range_to', '9000')
        ->set('issued_on', now()->toDateString())
        ->set('limit_date', now()->addYear()->toDateString())
        ->call('saveAuthorization')
        ->assertHasNoErrors();

    expect($anterior->refresh()->status)->toBe(AuthorizationStatus::Replaced)
        ->and($this->point->activeAuthorization(FiscalDocumentType::Invoice)->cai)
        ->toBe('AAAAAA-BBBBBB-CCCCCC-DDDDDD-EEEEEE-77');
});

it('rechaza un rango que se cruza con otro ya cargado', function () {
    // La sembrada va del 1 al 5000; ésta se le mete dentro.
    Livewire::test(FiscalPointIndex::class)
        ->call('newAuthorization', $this->point->id)
        ->set('cai', 'FFFFFF-FFFFFF-FFFFFF-FFFFFF-FFFFFF-11')
        ->set('range_from', '4000')
        ->set('range_to', '6000')
        ->set('issued_on', now()->toDateString())
        ->set('limit_date', now()->addYear()->toDateString())
        ->call('saveAuthorization')
        ->assertHasErrors('cai');
});

it('rechaza un rango al revés y una fecha límite anterior a la autorización', function () {
    Livewire::test(FiscalPointIndex::class)
        ->call('newAuthorization', $this->point->id)
        ->set('cai', 'AAAAAA-BBBBBB-CCCCCC-DDDDDD-EEEEEE-88')
        ->set('range_from', '9000')
        ->set('range_to', '8000')
        ->set('issued_on', now()->toDateString())
        ->set('limit_date', now()->subMonth()->toDateString())
        ->call('saveAuthorization')
        ->assertHasErrors(['range_to', 'limit_date']);
});

it('no deja corregir una autorización que ya numeró', function () {
    $authorization = FiscalAuthorization::query()->firstOrFail();

    // Se emite una factura: la autorización deja de ser corregible.
    app(SaleService::class)->createAndIssue([
        'branch_id' => $this->branch->id,
        'customer_id' => makeCustomer()->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Cash,
        'deposit_account_id' => account('1.1.02.01')->id,
    ], [['product_id' => makeProduct()->id, 'quantity' => '1', 'unit_price' => '100.00']]);

    expect($authorization->refresh()->used())->toBe(1);

    Livewire::test(FiscalPointIndex::class)
        ->call('editAuthorization', $authorization->id)
        ->assertForbidden();
});

it('da de baja la autorización vigente', function () {
    $authorization = FiscalAuthorization::query()->firstOrFail();

    Livewire::test(FiscalPointIndex::class)
        ->call('confirmRetire', $authorization->id)
        ->call('retire')
        ->assertHasNoErrors();

    expect($authorization->refresh()->status)->toBe(AuthorizationStatus::Replaced);
});

/*
|--------------------------------------------------------------------------
| Avisos
|--------------------------------------------------------------------------
*/

it('avisa cuando el CAI está por vencer', function () {
    FiscalAuthorization::query()->update(['limit_date' => now()->addDays(10)->toDateString()]);

    Livewire::test(FiscalPointIndex::class)
        ->assertSee('Autorizaciones por renovar')
        ->assertSee('vence en 10 día(s)');
});

it('avisa cuando quedan pocos correlativos', function () {
    $authorization = FiscalAuthorization::query()->firstOrFail();

    // 4 900 de 5 000 usados: el 98 %.
    $authorization->forceFill(['next_number' => 4901])->save();

    Livewire::test(FiscalPointIndex::class)
        ->assertSee('Autorizaciones por renovar')
        ->assertSee('correlativos');
});

it('no molesta cuando la autorización está holgada', function () {
    Livewire::test(FiscalPointIndex::class)
        ->assertDontSee('Autorizaciones por renovar');
});

/*
|--------------------------------------------------------------------------
| Permisos
|--------------------------------------------------------------------------
*/

it('deja mirar al vendedor pero no tramitar', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::SALESPERSON);

    // Ve el estado de su CAI: es quien se queda sin correlativos a media venta.
    $this->get(route('fiscal.points.index'))->assertOk();

    Livewire::test(FiscalPointIndex::class)
        ->call('newPoint')
        ->assertForbidden();
});

it('le niega la pantalla al bodeguero', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::WAREHOUSE);

    $this->get(route('fiscal.points.index'))->assertForbidden();
});
