<?php

declare(strict_types=1);

use App\Domains\Api\Data\ApiScope;
use App\Domains\Api\Models\ApiToken;
use App\Domains\Api\Services\ApiTokenService;
use App\Domains\Fiscal\Models\FiscalAuthorization;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Partners\Models\Customer;
use App\Domains\Purchases\Services\PurchaseService;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\Auth;

/**
 * API pública v1.
 *
 * Lo que se comprueba aquí es que la API sea la misma aplicación por otra
 * puerta: el mismo aislamiento, los mismos permisos, el mismo motor contable. Una
 * API que hace las cosas «más directo» es una segunda implementación de las
 * reglas, y las dos se separan en la primera corrección que alguien olvida
 * replicar.
 */
beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->branch = mainBranch();

    // El contador no puede facturar; para los endpoints de escritura hace falta
    // alguien que sí. El token hereda los permisos de su dueño.
    $this->vendedor = actingAsUserOf($this->company, role: PermissionCatalog::SALESPERSON);
});

/**
 * Emite un token con los alcances dados y devuelve el secreto en claro.
 *
 * @param  array<int, string>  $scopes
 */
function tokenFor(object $ctx, array $scopes, $user = null, $company = null): string
{
    $company ??= $ctx->company;
    $user ??= $ctx->vendedor;

    $plain = app(CompanyContext::class)->runFor($company, fn () => app(ApiTokenService::class)
        ->issue($user, 'Prueba', $scopes)['plain']);

    // Emitir un token es un acto de alguien con sesión; **usarlo** no. A partir
    // de aquí la prueba es un integrador de fuera, sin cookie ni sesión, que es
    // como llega una petición de verdad. Sin esto, el guard de Sanctum caería a
    // la sesión web y estaríamos probando otra cosa.
    Auth::logout();
    session()->flush();

    return $plain;
}

/**
 * Cabeceras de una petición autenticada, con el estado de autenticación limpio.
 *
 * `Auth::forgetGuards()` importa: el guard cachea el usuario que resolvió, y en
 * una prueba todas las peticiones comparten contenedor. En producción cada
 * petición empieza de cero; esto lo reproduce.
 *
 * @return array<string, string>
 */
function bearer(string $token): array
{
    Auth::forgetGuards();

    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

/*
|--------------------------------------------------------------------------
| Autenticación
|--------------------------------------------------------------------------
*/

it('rechaza una petición sin token', function () {
    $this->getJson('/api/v1/products')->assertUnauthorized();
});

it('rechaza un token inventado', function () {
    $this->getJson('/api/v1/products', bearer('1|noexiste'))->assertUnauthorized();
});

it('no deja entrar con la cookie del navegador', function () {
    // El guard de Sanctum cae a la sesión web cuando la hay. Para una API
    // pública eso sería un agujero: bastaría con estar logueado en otra pestaña
    // para saltarse los alcances del token. La sesión no es un token.
    $this->actingAs($this->vendedor);

    $this->getJson('/api/v1/products', ['Accept' => 'application/json'])
        ->assertUnauthorized()
        ->assertJsonPath('error.message', 'No se pudo autenticar el token.');
});

it('dice sobre qué empresa actúa el token', function () {
    $token = tokenFor($this, [ApiScope::CATALOG_READ]);

    $this->getJson('/api/v1/me', bearer($token))
        ->assertOk()
        ->assertJsonPath('data.company.id', $this->company->id)
        ->assertJsonPath('data.company.tax_id', $this->company->tax_id)
        ->assertJsonPath('data.token.scopes', [ApiScope::CATALOG_READ]);
});

it('rechaza un token revocado', function () {
    $token = tokenFor($this, [ApiScope::CATALOG_READ]);

    ApiToken::query()->update(['revoked_at' => now()]);

    $this->getJson('/api/v1/products', bearer($token))
        ->assertUnauthorized()
        ->assertJsonPath('error.message', 'Este token fue revocado.');
});

it('rechaza un token vencido', function () {
    $token = tokenFor($this, [ApiScope::CATALOG_READ]);

    ApiToken::query()->update(['expires_at' => now()->subDay()]);

    $this->getJson('/api/v1/products', bearer($token))->assertUnauthorized();
});

it('corta el token cuando su dueño se desactiva', function () {
    $token = tokenFor($this, [ApiScope::CATALOG_READ]);

    // Dar de baja a un empleado tiene que cortarle también las integraciones,
    // sin tener que acordarse de revocar sus tokens uno por uno.
    $this->vendedor->forceFill(['is_active' => false])->save();

    $this->getJson('/api/v1/products', bearer($token))
        ->assertForbidden()
        ->assertJsonPath('error.message', 'El usuario dueño del token está desactivado.');
});

it('corta el token cuando su dueño deja la empresa', function () {
    $token = tokenFor($this, [ApiScope::CATALOG_READ]);

    $this->vendedor->companies()->detach($this->company->id);

    $this->getJson('/api/v1/products', bearer($token))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Alcances y permisos
|--------------------------------------------------------------------------
*/

it('exige el alcance en el token', function () {
    $token = tokenFor($this, [ApiScope::CATALOG_READ]);

    $this->getJson('/api/v1/customers', bearer($token))
        ->assertForbidden()
        ->assertJsonPath('error.message', fn (string $m) => str_contains($m, 'customers:read'));
});

it('exige además el permiso del dueño, no solo el alcance', function () {
    // El bodeguero no ve documentos comerciales. Un token suyo con sales:read
    // no puede convertirse en una puerta trasera para saltarse su rol.
    $bodeguero = actingAsUserOf($this->company, role: PermissionCatalog::WAREHOUSE);
    $token = tokenFor($this, [ApiScope::SALES_READ], $bodeguero);

    $this->getJson('/api/v1/sales', bearer($token))
        ->assertForbidden()
        ->assertJsonPath('error.message', fn (string $m) => str_contains($m, 'no tiene permiso'));
});

it('no acepta un token sin alcances', function () {
    app(CompanyContext::class)->runFor(
        $this->company,
        fn () => app(ApiTokenService::class)->issue($this->vendedor, 'Vacío', []),
    );
})->throws(RuntimeException::class, 'sin alcances');

/*
|--------------------------------------------------------------------------
| Lectura
|--------------------------------------------------------------------------
*/

it('lista el catálogo paginado', function () {
    makeProduct('100.00');
    makeProduct('200.00');

    $token = tokenFor($this, [ApiScope::CATALOG_READ]);

    $this->getJson('/api/v1/products', bearer($token))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'code', 'name', 'price']], 'meta' => ['total']]);
});

it('devuelve los importes como cadena, no como número', function () {
    makeProduct('1234.56');
    $token = tokenFor($this, [ApiScope::CATALOG_READ]);

    $precio = $this->getJson('/api/v1/products', bearer($token))->json('data.0.price');

    // Como cadena, quien consume decide con qué precisión trabajar; como float,
    // el centavo se pierde antes de llegar a él.
    expect($precio)->toBeString()->toBe('1234.56');
});

it('acota cuántos registros se pueden pedir de una vez', function () {
    $token = tokenFor($this, [ApiScope::CATALOG_READ]);

    $this->getJson('/api/v1/products?per_page=100000', bearer($token))
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

it('consulta el saldo de un cliente', function () {
    $customer = makeCustomer(['credit_limit' => '90000.00', 'credit_days' => 30]);

    app(SaleService::class)->createAndIssue([
        'branch_id' => $this->branch->id,
        'customer_id' => $customer->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => makeProduct('500.00')->id, 'quantity' => '2', 'unit_price' => '500.00', 'tax_id' => tax()->id]]);

    $token = tokenFor($this, [ApiScope::CUSTOMERS_READ, ApiScope::RECEIVABLES_READ]);

    $this->getJson("/api/v1/customers/{$customer->id}/receivables", bearer($token))
        ->assertOk()
        ->assertJsonPath('data.outstanding', '1150.00')
        ->assertJsonCount(1, 'data.documents');
});

/*
|--------------------------------------------------------------------------
| Escritura
|--------------------------------------------------------------------------
*/

it('emite una factura por la API con su CAI y su partida', function () {
    $customer = makeCustomer();
    $product = makeProduct('500.00');
    $token = tokenFor($this, [ApiScope::SALES_WRITE, ApiScope::SALES_READ]);

    $response = $this->postJson('/api/v1/sales', [
        'customer_id' => $customer->id,
        'payment_condition' => 'cash',
        'items' => [
            ['product_id' => $product->id, 'quantity' => '2', 'unit_price' => '500.00', 'tax_id' => tax()->id],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => '1150.00', 'account_id' => account('1.1.01.01')->id],
        ],
    ], bearer($token));

    $response->assertCreated()
        ->assertJsonPath('data.number', '000-001-01-00000001')
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.totals.total', '1150.00');

    $sale = Sale::query()->firstOrFail();

    // Pasó por el motor: tiene partida cuadrada, igual que una factura hecha en
    // la pantalla.
    expect($sale->journalEntry())->not->toBeNull()
        ->and($sale->journalEntry()->isBalanced())->toBeTrue()
        ->and($sale->cai)->not->toBeNull();
});

it('deduce la bodega cuando el integrador no la manda', function () {
    $customer = makeCustomer();
    // Un producto con control de existencias: sin bodega, el motor se niega.
    $product = makeProduct('100.00', tracked: true);
    $supplier = makeSupplier();

    app(PurchaseService::class)->createAndReceive([
        'branch_id' => $this->branch->id,
        'warehouse_id' => warehouse()->id,
        'supplier_id' => $supplier->id,
        'supplier_invoice_number' => 'C-API-1',
        'date' => now()->subDay()->toDateString(),
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $product->id, 'quantity' => '10', 'unit_price' => '60.00']]);

    $token = tokenFor($this, [ApiScope::SALES_WRITE]);

    // Quien integra desde una tienda en línea no sabe de qué bodega sale la
    // mercadería. La API toma la predeterminada de la sucursal, igual que el POS.
    $this->postJson('/api/v1/sales', [
        'customer_id' => $customer->id,
        'items' => [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '100.00']],
        'payments' => [['method' => 'cash', 'amount' => '115.00', 'account_id' => account('1.1.01.01')->id]],
    ], bearer($token))->assertCreated();

    expect(Sale::query()->whereNotNull('number')->firstOrFail()->warehouse_id)->not->toBeNull();
});

it('devuelve un 422 con el motivo cuando no hay existencia', function () {
    $customer = makeCustomer();
    $product = makeProduct('100.00', tracked: true);
    $token = tokenFor($this, [ApiScope::SALES_WRITE]);

    // Quedarse sin mercadería es una condición de negocio corriente, no un
    // fallo del servidor: el integrador tiene que poder distinguirla.
    $this->postJson('/api/v1/sales', [
        'customer_id' => $customer->id,
        'items' => [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '100.00']],
        'payments' => [['method' => 'cash', 'amount' => '115.00', 'account_id' => account('1.1.01.01')->id]],
    ], bearer($token))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'cannot_issue')
        ->assertJsonPath('error.message', fn (string $m) => str_contains($m, 'existencia suficiente'));
});

it('no emite dos veces el mismo intento', function () {
    $customer = makeCustomer();
    $product = makeProduct('100.00');
    $token = tokenFor($this, [ApiScope::SALES_WRITE]);

    $payload = [
        'customer_id' => $customer->id,
        'payment_condition' => 'cash',
        'items' => [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '100.00']],
        'payments' => [['method' => 'cash', 'amount' => '115.00', 'account_id' => account('1.1.01.01')->id]],
    ];

    $headers = [...bearer($token), 'Idempotency-Key' => 'pedido-web-4471'];

    $primera = $this->postJson('/api/v1/sales', $payload, $headers)->assertCreated();

    // El reintento del cliente devuelve la misma factura, no otra: sin esto un
    // timeout gastaría dos correlativos del SAR y cobraría dos veces.
    $segunda = $this->postJson('/api/v1/sales', $payload, $headers)
        ->assertOk()
        ->assertHeader('Idempotent-Replay', 'true');

    expect($segunda->json('data.id'))->toBe($primera->json('data.id'))
        ->and(Sale::query()->count())->toBe(1);
});

it('devuelve el motivo cuando la factura no se puede emitir', function () {
    $customer = makeCustomer();
    $product = makeProduct('100.00');
    $token = tokenFor($this, [ApiScope::SALES_WRITE]);

    // Sin CAI vigente no hay factura, y la API lo dice con el mismo mensaje que
    // la pantalla.
    FiscalAuthorization::query()->delete();

    $this->postJson('/api/v1/sales', [
        'customer_id' => $customer->id,
        'items' => [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '100.00']],
        'payments' => [['method' => 'cash', 'amount' => '115.00', 'account_id' => account('1.1.01.01')->id]],
    ], bearer($token))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'cannot_issue')
        ->assertJsonPath('error.message', fn (string $m) => str_contains($m, 'autorización vigente'));

    expect(Sale::query()->count())->toBe(0);
});

it('valida el cuerpo y dice qué campo falla', function () {
    $token = tokenFor($this, [ApiScope::SALES_WRITE]);

    $this->postJson('/api/v1/sales', ['items' => []], bearer($token))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['fields' => ['customer_id', 'items']]]);
});

it('crea un cliente por la API', function () {
    $token = tokenFor($this, [ApiScope::CUSTOMERS_WRITE]);

    $this->postJson('/api/v1/customers', [
        'name' => 'Tienda en Línea, S.A.',
        'tax_id' => '08019055443322',
    ], bearer($token))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Tienda en Línea, S.A.')
        // Nace de contado: una integración no debería poder abrir crédito sola.
        ->assertJsonPath('data.credit.limit', '0.00');

    expect(Customer::query()->where('tax_id', '08019055443322')->exists())->toBeTrue();
});

it('anula una factura por la API, con motivo', function () {
    $customer = makeCustomer();
    $product = makeProduct('100.00');
    $token = tokenFor($this, [ApiScope::SALES_WRITE]);

    $sale = app(SaleService::class)->createAndIssue([
        'branch_id' => $this->branch->id,
        'customer_id' => $customer->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Cash,
        'deposit_account_id' => account('1.1.01.01')->id,
    ], [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '100.00']]);

    $this->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'no'], bearer($token))
        ->assertStatus(422);

    $this->postJson("/api/v1/sales/{$sale->id}/void", [
        'reason' => 'El cliente canceló el pedido',
    ], bearer($token))->assertOk();

    expect($sale->refresh()->status)->toBe(SaleStatus::Voided);
});
