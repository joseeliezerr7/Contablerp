<?php

declare(strict_types=1);

use App\Domains\Api\Data\ApiScope;
use App\Domains\Api\Services\ApiTokenService;
use App\Domains\Catalog\Models\Product;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Identity\Services\RoleProvisioner;
use App\Domains\Partners\Models\Customer;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleService;
use App\Domains\Tenancy\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\Auth;

/**
 * Invariante de la Fase 9C: **la API no es una puerta trasera al aislamiento.**
 *
 * Una API pública multiplica la superficie: donde la aplicación tenía pantallas
 * que solo se alcanzan con sesión, ahora hay URLs que cualquiera puede llamar con
 * un token. Si el filtro por empresa dependiera de recordar un `where` en cada
 * consulta, este sería el sitio donde se olvidaría.
 *
 * Lo que se prueba no es que los endpoints funcionen —eso está en su propia
 * suite— sino que **no exista forma** de que un token de una empresa toque datos
 * de otra: ni listando, ni pidiendo un id ajeno, ni mandándolo en el cuerpo.
 */
beforeEach(function () {
    // Dos empresas completas, cada una con su gente y sus datos.
    $this->primera = accountingCompany();
    $this->segunda = accountingCompany();

    $this->vendedorPrimera = actingAsUserOf($this->primera, role: PermissionCatalog::SALESPERSON);
    $this->vendedorSegunda = actingAsUserOf($this->segunda, role: PermissionCatalog::SALESPERSON);
});

/**
 * Crea un cliente, un producto y una factura dentro de la empresa dada.
 *
 * @return array{customer: Customer, product: Product, sale: Sale}
 */
function dataInside(Company $company, string $customerName): array
{
    return app(CompanyContext::class)->runFor($company, function () use ($customerName): array {
        $customer = makeCustomer(['name' => $customerName]);
        $product = makeProduct('100.00');

        $sale = app(SaleService::class)->createAndIssue([
            'branch_id' => mainBranch()->id,
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'payment_condition' => PaymentCondition::Cash,
            'deposit_account_id' => account('1.1.01.01')->id,
        ], [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '100.00']]);

        return ['customer' => $customer, 'product' => $product, 'sale' => $sale];
    });
}

/**
 * Token con todos los alcances sobre una empresa, y sesión cerrada después.
 */
function fullToken(User $user, Company $company): string
{
    // Emitir es un acto de alguien con sesión **en esa empresa**: el servicio se
    // niega a emitir sobre una empresa que no es del emisor, y eso es parte de
    // lo que se está protegiendo.
    Auth::login($user);

    $plain = app(CompanyContext::class)->runFor($company, fn () => app(ApiTokenService::class)
        ->issue($user, 'Integración', ApiScope::values())['plain']);

    // De aquí en adelante la prueba es un integrador de fuera: sin sesión, como
    // llega una petición de verdad.
    Auth::logout();
    session()->flush();

    return $plain;
}

/**
 * Cabeceras de una petición de API, con el estado de autenticación limpio.
 *
 * `Auth::forgetGuards()` no es maquillaje: el guard de Laravel **cachea** el
 * usuario que resolvió, y dentro de una prueba todas las peticiones comparten el
 * mismo contenedor. Sin esto, la segunda llamada reutiliza el usuario y el token
 * de la primera —incluida su empresa— y una prueba de aislamiento con dos tokens
 * pasaría en verde mientras la fuga sigue ahí. En producción cada petición nace
 * con el contenedor limpio, que es justo lo que esta línea reproduce.
 *
 * @return array<string, string>
 */
function authHeaders(string $token): array
{
    Auth::forgetGuards();

    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

it('solo lista lo de su propia empresa', function () {
    dataInside($this->primera, 'Cliente de la Primera');
    dataInside($this->segunda, 'Cliente de la Segunda');

    $token = fullToken($this->vendedorPrimera, $this->primera);

    $clientes = $this->getJson('/api/v1/customers', authHeaders($token))
        ->assertOk()
        ->json('data');

    $nombres = array_column($clientes, 'name');

    expect($nombres)->toContain('Cliente de la Primera')
        ->and($nombres)->not->toContain('Cliente de la Segunda');

    // Y lo mismo con las facturas: una sola, la suya.
    $facturas = $this->getJson('/api/v1/sales', authHeaders($token))->assertOk()->json('data');

    expect($facturas)->toHaveCount(1);
});

it('no deja leer un registro de otra empresa aunque se sepa su id', function () {
    $ajeno = dataInside($this->segunda, 'Cliente de la Segunda');

    $token = fullToken($this->vendedorPrimera, $this->primera);

    // El id existe y es válido; lo que no es válido es que se lo pida este
    // token. La respuesta es 404 y no 403 a propósito: confirmar que el
    // registro existe ya sería filtrar información.
    $this->getJson("/api/v1/customers/{$ajeno['customer']->id}", authHeaders($token))
        ->assertNotFound();

    $this->getJson("/api/v1/sales/{$ajeno['sale']->id}", authHeaders($token))
        ->assertNotFound();

    $this->getJson("/api/v1/products/{$ajeno['product']->id}", authHeaders($token))
        ->assertNotFound();

    $this->getJson("/api/v1/customers/{$ajeno['customer']->id}/receivables", authHeaders($token))
        ->assertNotFound();
});

it('no deja facturarle a un cliente de otra empresa', function () {
    $ajeno = dataInside($this->segunda, 'Cliente de la Segunda');
    $propio = dataInside($this->primera, 'Cliente de la Primera');

    $token = fullToken($this->vendedorPrimera, $this->primera);

    // Cliente ajeno, producto propio.
    $this->postJson('/api/v1/sales', [
        'customer_id' => $ajeno['customer']->id,
        'items' => [['product_id' => $propio['product']->id, 'quantity' => '1', 'unit_price' => '100.00']],
        'payments' => [['method' => 'cash', 'amount' => '115.00', 'account_id' => account('1.1.01.01')->id]],
    ], authHeaders($token))->assertStatus(422);

    // Solo existe la factura que creó el sembrado de cada empresa.
    expect(Sale::acrossCompanies()->where('company_id', $this->primera->id)->count())->toBe(1);
});

it('no deja meter un producto de otra empresa en una factura', function () {
    $ajeno = dataInside($this->segunda, 'Cliente de la Segunda');
    $propio = dataInside($this->primera, 'Cliente de la Primera');

    $token = fullToken($this->vendedorPrimera, $this->primera);

    $this->postJson('/api/v1/sales', [
        'customer_id' => $propio['customer']->id,
        'items' => [['product_id' => $ajeno['product']->id, 'quantity' => '1', 'unit_price' => '100.00']],
        'payments' => [['method' => 'cash', 'amount' => '115.00', 'account_id' => account('1.1.01.01')->id]],
    ], authHeaders($token))->assertStatus(422);

    expect(Sale::acrossCompanies()->where('company_id', $this->primera->id)->count())->toBe(1);
});

it('no deja anular una factura de otra empresa', function () {
    $ajeno = dataInside($this->segunda, 'Cliente de la Segunda');

    $token = fullToken($this->vendedorPrimera, $this->primera);

    $this->postJson("/api/v1/sales/{$ajeno['sale']->id}/void", [
        'reason' => 'Intento de anular lo que no es mío',
    ], authHeaders($token))->assertNotFound();

    expect($ajeno['sale']->refresh()->isIssued())->toBeTrue();
});

it('el cliente creado por API nace en la empresa del token', function () {
    $token = fullToken($this->vendedorPrimera, $this->primera);

    $id = $this->postJson('/api/v1/customers', [
        'name' => 'Creado por integración',
    ], authHeaders($token))->assertCreated()->json('data.id');

    $creado = Customer::acrossCompanies()->findOrFail($id);

    // La empresa no viene del cuerpo de la petición: viene del token. Es la
    // misma regla que en la web, donde viene de la sesión.
    expect($creado->company_id)->toBe($this->primera->id)
        ->and($creado->company_id)->not->toBe($this->segunda->id);
});

it('ignora cualquier intento de elegir empresa desde fuera', function () {
    dataInside($this->segunda, 'Cliente de la Segunda');

    $token = fullToken($this->vendedorPrimera, $this->primera);

    // Ni por query string ni por cuerpo: la empresa del token manda.
    $respuesta = $this->getJson(
        '/api/v1/customers?company_id='.$this->segunda->id.'&company='.$this->segunda->id,
        authHeaders($token),
    )->assertOk();

    expect(array_column($respuesta->json('data'), 'name'))
        ->not->toContain('Cliente de la Segunda');

    $this->postJson('/api/v1/customers', [
        'name' => 'Con empresa inyectada',
        'company_id' => $this->segunda->id,
    ], authHeaders($token))->assertCreated();

    expect(Customer::acrossCompanies()->where('name', 'Con empresa inyectada')->value('company_id'))
        ->toBe($this->primera->id);
});

it('dos tokens del mismo usuario sobre empresas distintas no se mezclan', function () {
    // Un contador externo que lleva las dos: cada empresa, su token.
    $externo = User::factory()->create();

    $externo->companies()->attach([$this->primera->id => [], $this->segunda->id => []]);
    app(RoleProvisioner::class)
        ->assign($externo, $this->primera, PermissionCatalog::SALESPERSON);
    app(RoleProvisioner::class)
        ->assign($externo, $this->segunda, PermissionCatalog::SALESPERSON);

    dataInside($this->primera, 'Cliente de la Primera');
    dataInside($this->segunda, 'Cliente de la Segunda');

    $tokenPrimera = fullToken($externo, $this->primera);
    $tokenSegunda = fullToken($externo, $this->segunda);

    $conPrimera = array_column(
        $this->getJson('/api/v1/customers', authHeaders($tokenPrimera))->json('data'),
        'name',
    );

    $conSegunda = array_column(
        $this->getJson('/api/v1/customers', authHeaders($tokenSegunda))->json('data'),
        'name',
    );

    expect($conPrimera)->toContain('Cliente de la Primera')
        ->and($conPrimera)->not->toContain('Cliente de la Segunda')
        ->and($conSegunda)->toContain('Cliente de la Segunda')
        ->and($conSegunda)->not->toContain('Cliente de la Primera');
});
