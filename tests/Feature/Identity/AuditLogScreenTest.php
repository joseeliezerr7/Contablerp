<?php

declare(strict_types=1);

use App\Domains\Identity\Data\AuditNarrator;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Identity\Models\AuditLog;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleService;
use App\Livewire\Identity\AuditIndex;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/**
 * La pantalla de bitácora.
 *
 * Lo que se prueba aquí no es que la consulta funcione —eso es Eloquent— sino
 * las dos cosas que la hacen servir de algo:
 *
 * 1. **Que no filtre entre empresas.** `AuditLog` es el único modelo sin el
 *    scope global de empresa, así que aquí el aislamiento es código, no
 *    infraestructura, y una prueba tiene que vigilarlo.
 * 2. **Que se lea.** Un renglón que dice `voided` sobre
 *    `App\Domains\Sales\Models\Sale` no responde «¿quién anuló esta factura?».
 */
beforeEach(function () {
    [$this->company, $this->accountant] = accountingCompanyWithAccountant();
    $this->admin = actingAsUserOf($this->company, role: PermissionCatalog::ADMIN);
});

/**
 * Emite y anula una factura de contado: deja en la bitácora los dos eventos que
 * de verdad se consultan.
 */
function issuedSale(): Sale
{
    $customer = makeCustomer();
    $product = makeProduct('1000.00');

    return app(SaleService::class)->createAndIssue([
        'branch_id' => mainBranch()->id,
        'customer_id' => $customer->id,
        'date' => now()->toDateString(),
        'payment_condition' => PaymentCondition::Cash,
        'deposit_account_id' => account('1.1.02.01')->id,
    ], [
        ['product_id' => $product->id, 'quantity' => '2', 'unit_price' => '1000.00'],
    ]);
}

/*
|--------------------------------------------------------------------------
| Aislamiento: la razón de ser de esta prueba
|--------------------------------------------------------------------------
*/

it('no muestra movimientos de otra empresa', function () {
    // Una factura anulada en la empresa ajena, con un motivo reconocible.
    $otra = accountingCompany();

    app(CompanyContext::class)->runFor($otra, function (): void {
        actingAsUserOf(app(CompanyContext::class)->companyOrFail(), role: PermissionCatalog::ACCOUNTANT);
        $sale = issuedSale();
        app(SaleService::class)->void($sale, 'Motivo de la empresa ajena');
    });

    // De vuelta en la propia.
    $this->actingAs($this->admin);
    app(CompanyContext::class)->set($this->company);

    $sale = issuedSale();
    app(SaleService::class)->void($sale, 'Motivo de la empresa propia');

    Livewire::test(AuditIndex::class)
        ->assertSee('Motivo de la empresa propia')
        ->assertDontSee('Motivo de la empresa ajena');
});

it('no abre el detalle de un movimiento de otra empresa', function () {
    $otra = accountingCompany();

    $ajeno = app(CompanyContext::class)->runFor($otra, function (): AuditLog {
        actingAsUserOf(app(CompanyContext::class)->companyOrFail(), role: PermissionCatalog::ACCOUNTANT);
        issuedSale();

        return AuditLog::query()->where('company_id', app(CompanyContext::class)->idOrFail())->firstOrFail();
    });

    $this->actingAs($this->admin);
    app(CompanyContext::class)->set($this->company);

    // Ni siquiera llega a la policy: la consulta base no lo encuentra, y por
    // HTTP eso es un 404. Lo que importa es que el detalle nunca se abra.
    Livewire::test(AuditIndex::class)
        ->call('show', $ajeno->id);
})->throws(ModelNotFoundException::class);

/*
|--------------------------------------------------------------------------
| Que se lea
|--------------------------------------------------------------------------
*/

it('cuenta en español quién hizo qué', function () {
    $sale = issuedSale();

    Livewire::test(AuditIndex::class)
        ->assertSee('Emitió')
        // El folio fiscal, no «Sale #3».
        ->assertSee($sale->document_number)
        ->assertSee($this->admin->name)
        ->assertDontSee('App\Domains');
});

it('muestra el motivo de la anulación en la lista', function () {
    $sale = issuedSale();
    app(SaleService::class)->void($sale, 'El cliente devolvió la mercadería completa');

    Livewire::test(AuditIndex::class)
        ->assertSee('Anuló')
        ->assertSee('El cliente devolvió la mercadería completa');
});

it('traduce los valores del cambio usando el enum del propio modelo', function () {
    $sale = issuedSale();
    app(SaleService::class)->void($sale, 'Anulada por error de digitación');

    $log = AuditLog::query()
        ->where('event', 'voided')
        ->where('auditable_type', Sale::class)
        ->firstOrFail();

    $changes = collect(app(AuditNarrator::class)->changes($log))
        ->firstWhere('field', 'Estado');

    // La columna guarda «issued» y «voided»; la pantalla dice lo que dice el enum.
    expect($changes)->not->toBeNull()
        ->and($changes['from'])->toBe('Emitida')
        ->and($changes['to'])->toBe('Anulada');
});

it('presenta los importes como dinero y no como decimal de la base', function () {
    // Dos unidades a 1 000 con ISV del 15 %: 2 300 exactos.
    issuedSale();

    $log = AuditLog::query()
        ->where('event', 'issued')
        ->where('auditable_type', Sale::class)
        ->firstOrFail();

    $narrator = app(AuditNarrator::class);
    $total = collect($narrator->changes($log))->firstWhere('field', 'Total');

    expect($total['to'])->toBe('2,300.00')
        // La cantidad no es dinero: sin decimales que no dicen nada.
        ->and($narrator->value(Sale::class, 'quantity', '2.0000'))->toBe('2');
});

/**
 * Los nombres de columna que la aplicación registra hoy y que humanizados
 * saldrían en inglés. Si mañana se registra uno nuevo sin etiqueta, aparece en
 * pantalla como «Opening Float» y nadie se da cuenta hasta que lo ve un cliente.
 */
it('traduce los nombres de campo que no se entenderían', function (string $field, string $label) {
    expect(app(AuditNarrator::class)->field($field))->toBe($label);
})->with([
    ['opening_float', 'Fondo inicial'],
    ['book_value', 'Valor en libros'],
    ['statement_balance', 'Saldo del estado de cuenta'],
    ['net_profit', 'Resultado'],
    ['total_debit', 'Total debe'],
    ['scopes', 'Permisos del token'],
    ['limit_date', 'Fecha límite de emisión'],
    ['cleared_on', 'Cobrado el'],
    // La llave de una relación se lee por lo que es, sin el «id» y en español.
    ['customer_id', 'Cliente'],
    ['warehouse_id', 'Bodega'],
    ['deposit_account_id', 'Cuenta de depósito'],
    ['bank_account_id', 'Cuenta bancaria'],
    ['from_warehouse_id', 'Bodega de origen'],
    // `tax_id` es el RTN, no una llave a la tabla de impuestos.
    ['tax_id', 'RTN'],
]);

it('abre el detalle con el equipo desde el que se hizo', function () {
    issuedSale();

    $log = AuditLog::query()->where('event', 'issued')->firstOrFail();

    Livewire::test(AuditIndex::class)
        ->call('show', $log->id)
        ->assertSet('showingId', $log->id)
        ->assertSee('Detalle del movimiento')
        ->assertSee('Desde qué equipo');
});

it('sobrevive a un registro que ya no existe', function () {
    // Un borrador eliminado deja su evento en la bitácora sin nada que apuntar.
    // La bitácora tiene que seguir leyéndose: es justamente cuando más importa.
    $log = AuditLog::query()->create([
        'company_id' => $this->company->id,
        'user_id' => $this->admin->id,
        'event' => 'deleted',
        'auditable_type' => Sale::class,
        'auditable_id' => 999999,
        'module' => 'sales',
        'old_values' => ['status' => 'draft'],
    ]);

    Livewire::test(AuditIndex::class)
        ->assertSee('Eliminó')
        ->assertSee('#999999')
        ->call('show', $log->id)
        ->assertSee('Detalle del movimiento');
});

/*
|--------------------------------------------------------------------------
| Filtros
|--------------------------------------------------------------------------
*/

it('filtra por persona, módulo y fecha', function () {
    issuedSale();

    // El contador no hizo nada: filtrar por él deja la lista vacía.
    Livewire::test(AuditIndex::class)
        ->set('userFilter', (string) $this->accountant->id)
        ->assertSee('Ningún movimiento con esos filtros')
        ->set('moduleFilter', '')
        ->call('clearFilters')
        ->assertSee('Emitió');

    Livewire::test(AuditIndex::class)
        ->set('moduleFilter', 'treasury')
        ->assertSee('Ningún movimiento con esos filtros');

    Livewire::test(AuditIndex::class)
        ->set('from', now()->addDay()->toDateString())
        ->assertSee('Ningún movimiento con esos filtros');
});

it('solo ofrece los módulos que la empresa realmente usó, en español', function () {
    issuedSale();

    // Una factura de contado toca ventas, contabilidad y el CAI. Nada más:
    // no genera cuenta por cobrar ni movimiento de tesorería.
    Livewire::test(AuditIndex::class)
        ->assertSee('Ventas')
        ->assertSee('Contabilidad')
        ->assertDontSee('Tesorería')
        ->assertDontSee('Cuentas por cobrar')
        // Y en ningún caso el nombre del namespace.
        ->assertDontSee('Sales')
        ->assertDontSee('Accounting');
});

/*
|--------------------------------------------------------------------------
| Permisos
|--------------------------------------------------------------------------
*/

it('le niega la pantalla a quien no audita', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::CASHIER);

    $this->get(route('audit.index'))->assertForbidden();
});

it('la deja leer al auditor', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::AUDITOR);

    $this->get(route('audit.index'))->assertOk();
});
