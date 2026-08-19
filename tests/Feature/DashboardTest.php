<?php

declare(strict_types=1);

use App\Domains\Fiscal\Enums\AuthorizationStatus;
use App\Domains\Fiscal\Models\FiscalAuthorization;
use App\Domains\Fiscal\Services\FiscalAuthorizationService;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleService;
use App\Domains\Tenancy\Models\Company;
use App\Livewire\Dashboard;
use App\Support\Tenancy\CompanyContext;
use Livewire\Livewire;

/**
 * El dashboard.
 *
 * Dos cosas se prueban aquí, y ninguna es «que la pantalla cargue»:
 *
 * 1. **Que cada panel respete el permiso.** Un cajero entra al mismo dashboard
 *    que el dueño; no puede salirle la utilidad del mes ni el saldo del banco.
 * 2. **Que las cifras sean las de la empresa activa.** Es el mismo aislamiento
 *    que el resto del sistema, pero aquí se agregan sumas de seis módulos a la
 *    vez, y un `sum()` sin scope no avisa: devuelve un número más grande.
 */
beforeEach(function () {
    [$this->company, $this->accountant] = accountingCompanyWithAccountant();
});

/**
 * Factura de contado por el monto indicado, ya emitida y contabilizada.
 */
function dashboardSale(string $unitPrice, string $quantity = '1'): Sale
{
    return app(SaleService::class)->createAndIssue([
        'branch_id' => mainBranch()->id,
        'customer_id' => makeCustomer()->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Cash,
        'deposit_account_id' => account('1.1.02.01')->id,
    ], [
        ['product_id' => makeProduct($unitPrice)->id, 'quantity' => $quantity, 'unit_price' => $unitPrice],
    ]);
}

/*
|--------------------------------------------------------------------------
| Las cifras
|--------------------------------------------------------------------------
*/

it('suma las ventas del mes con el impuesto incluido', function () {
    // 1 000 + 15 % de ISV = 1 150 por factura, dos facturas = 2 300.
    dashboardSale('1000.00');
    dashboardSale('1000.00');

    $sales = Livewire::test(Dashboard::class)->viewData('sales');

    expect($sales['total']->format())->toBe('2,300.00')
        ->and($sales['count'])->toBe(2)
        // Sin mes anterior no hay variación: no se divide por cero.
        ->and($sales['change'])->toBeNull();
});

it('no cuenta la factura anulada', function () {
    $vive = dashboardSale('1000.00');
    $muere = dashboardSale('500.00');

    app(SaleService::class)->void($muere, 'Anulada para la prueba');

    $sales = Livewire::test(Dashboard::class)->viewData('sales');

    expect($sales['total']->format())->toBe('1,150.00')
        ->and($sales['count'])->toBe(1)
        ->and($vive->refresh()->isIssued())->toBeTrue();
});

it('devuelve doce meses aunque solo uno tenga ventas', function () {
    dashboardSale('1000.00');

    $series = Livewire::test(Dashboard::class)->viewData('salesByMonth');

    // Los meses en cero no se saltan: un mes sin ventas es un dato, y omitirlo
    // deformaría la gráfica.
    expect($series)->toHaveCount(12);

    $conVentas = array_filter($series, fn (array $m) => ! $m['total']->isZero());

    expect($conVentas)->toHaveCount(1)
        ->and(end($series)['total']->format())->toBe('1,150.00');
});

it('no suma las ventas de otra empresa', function () {
    dashboardSale('1000.00');

    $otra = accountingCompany();

    app(CompanyContext::class)->runFor($otra, function (): void {
        actingAsUserOf(app(CompanyContext::class)->companyOrFail(), role: PermissionCatalog::ACCOUNTANT);
        dashboardSale('9000.00');
    });

    $this->actingAs($this->accountant);
    app(CompanyContext::class)->set($this->company);

    expect(Livewire::test(Dashboard::class)->viewData('sales')['total']->format())
        ->toBe('1,150.00');
});

/*
|--------------------------------------------------------------------------
| Permisos: el dato no se calcula si no se puede ver
|--------------------------------------------------------------------------
*/

it('no le da el resultado del mes al cajero', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::CASHIER);

    $data = Livewire::test(Dashboard::class);

    // Null, no un arreglo escondido con CSS: la consulta no se ejecuta.
    expect($data->viewData('profit'))->toBeNull()
        ->and($data->viewData('cashAccounts'))->toBe([])
        ->and($data->viewData('payables'))->toBeNull();

    $data->assertDontSee('Resultado del mes')
        ->assertDontSee('Caja y bancos');
});

it('le da al contador todo lo que su rol incluye', function () {
    dashboardSale('1000.00');

    Livewire::test(Dashboard::class)
        ->assertSee('Ventas del mes')
        ->assertSee('Por cobrar')
        ->assertSee('Por pagar')
        ->assertSee('Resultado del mes')
        ->assertSee('Caja y bancos');
});

it('el bodeguero no ve ninguna cifra de plata y se le explica', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::WAREHOUSE);

    Livewire::test(Dashboard::class)
        ->assertDontSee('Ventas del mes')
        ->assertDontSee('Por cobrar')
        ->assertDontSee('Resultado del mes')
        // En vez de una pantalla vacía sin explicación.
        ->assertSee('Nada que mostrar todavía');
});

/*
|--------------------------------------------------------------------------
| Avisos
|--------------------------------------------------------------------------
*/

/**
 * Deja vigente un CAI corto, de diez correlativos.
 *
 * La empresa de prueba viene con uno de 5 000, y no se puede simplemente añadir
 * otro: la guarda de solapamiento no admite dos rangos que compartan números, y
 * el índice único no admite dos vigentes del mismo tipo. Hay que reemplazarlo,
 * que es exactamente lo que hace una empresa cuando se le acaba.
 */
function withShortAuthorization(Company $company): void
{
    app(FiscalAuthorizationService::class)->retire(
        FiscalAuthorization::query()->active()->firstOrFail(),
        AuthorizationStatus::Replaced,
    );

    withFiscalAuthorization($company, overrides: ['range_from' => 6000, 'range_to' => 6009]);
}

it('avisa del CAI que está por agotarse', function () {
    withShortAuthorization($this->company);

    // Nueve de diez correlativos consumidos: 90 %.
    for ($i = 0; $i < 9; $i++) {
        dashboardSale('100.00');
    }

    $alerts = Livewire::test(Dashboard::class)->viewData('alerts');
    $cai = array_values(array_filter($alerts, fn (array $a) => $a['title'] === 'CAI por renovar'));

    expect($cai)->not->toBeEmpty()
        ->and($cai[0]['detail'])->toContain('90%');
});

it('no le avisa del CAI a quien no puede renovarlo', function () {
    withShortAuthorization($this->company);

    actingAsUserOf($this->company, role: PermissionCatalog::CASHIER);

    $alerts = Livewire::test(Dashboard::class)->viewData('alerts');

    expect(array_filter($alerts, fn (array $a) => $a['title'] === 'CAI por renovar'))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| El login
|--------------------------------------------------------------------------
*/

it('el campo de contraseña del login es de tipo password', function () {
    // Regresión: el botón de «mostrar la contraseña» se escribió primero con
    // Alpine, que en esta pantalla no existe —lo inyecta Livewire y el login no
    // es un componente Livewire—. Al enlazar el `type` en vez de escribirlo, el
    // campo quedaba en texto plano y la contraseña se veía al teclearla.
    auth()->logout();

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('type="password"', escape: false);
});

it('el login muestra la marca y lo que hace el sistema', function () {
    auth()->logout();

    $this->get(route('login'))
        ->assertSee('Cerquín')
        ->assertSee('Facturás con tu CAI');
});
