<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Enums\SubscriptionStatus;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Billing\Services\SignupService;
use App\Domains\Billing\Services\SubscriptionService;
use App\Livewire\Admin\TenantIndex;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/**
 * El panel del proveedor.
 *
 * Lo que hay que proteger aquí no es una funcionalidad, es una frontera: esta es
 * la única pantalla del sistema que ve datos de todas las cuentas, y un cliente
 * que llegue a ella vería los nombres, los planes y el consumo de sus
 * competidores.
 */
beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->signup = app(SignupService::class);

    $this->superadmin = User::query()->create([
        'name' => 'Soporte',
        'email' => 'soporte@proveedor.hn',
        'password' => bcrypt('secreto-largo'),
        'is_super_admin' => true,
    ]);
});

function altaDePrueba(object $ctx, string $plan = 'emprende', string $email = 'duena@negocio.hn'): array
{
    return $ctx->signup->register([
        'name' => 'Dueña',
        'email' => $email,
        'password' => 'una-contraseña-larga',
        'legal_name' => 'Negocio de Prueba, S.A.',
        'tax_id' => '08019011111111',
    ], Plan::query()->where('code', $plan)->firstOrFail());
}

it('le niega el panel a un usuario cliente', function () {
    ['user' => $cliente] = altaDePrueba($this);

    $this->actingAs($cliente)->get('/admin/cuentas')->assertForbidden();
});

it('le niega el panel a quien no ha iniciado sesión', function () {
    $this->get('/admin/cuentas')->assertRedirect(route('login'));
});

it('se lo muestra al superadministrador', function () {
    altaDePrueba($this);

    $this->actingAs($this->superadmin)
        ->get('/admin/cuentas')
        ->assertOk()
        ->assertSee('Negocio de Prueba, S.A.');
});

it('manda al superadministrador a su panel y no a «sin empresa»', function () {
    $this->actingAs($this->superadmin)
        ->get('/dashboard')
        ->assertRedirect(route('admin.tenants.index'));
});

it('muestra el ingreso recurrente y el consumo de cada cuenta', function () {
    ['subscription' => $suscripcion] = altaDePrueba($this, 'corporativo');

    app(SubscriptionService::class)->activate($suscripcion);

    Livewire::actingAs($this->superadmin)
        ->test(TenantIndex::class)
        ->assertSee('2,900.00')      // el ingreso recurrente del plan corporativo
        ->assertSee('1 empresa(s)'); // la que creó el alta
});

it('filtra por estado y por nombre', function () {
    altaDePrueba($this, 'emprende', 'una@negocio.hn');
    altaDePrueba($this, 'negocio', 'otra@distinta.hn');

    // El alta pone el mismo nombre legal a las dos, así que se distinguen por el
    // slug, que sí es único.
    $segunda = Subscription::query()->latest('id')->first();
    $segunda->tenant->update(['name' => 'Ferretería Funez']);

    Livewire::actingAs($this->superadmin)
        ->test(TenantIndex::class)
        ->set('search', 'Ferretería')
        ->assertSee('Ferretería Funez')
        ->assertDontSee('Negocio de Prueba, S.A.')
        ->set('search', '')
        ->set('statusFilter', SubscriptionStatus::Cancelled->value)
        ->assertSee('Todavía no hay cuentas registradas');
});

it('suspende una cuenta desde el panel, con motivo', function () {
    ['subscription' => $suscripcion] = altaDePrueba($this);

    Livewire::actingAs($this->superadmin)
        ->test(TenantIndex::class)
        ->call('confirm', $suscripcion->id, 'suspend')
        ->set('reason', '')
        ->call('apply')
        ->assertHasErrors(['reason' => 'required'])
        ->set('reason', 'Falta de pago desde marzo')
        ->call('apply')
        ->assertHasNoErrors();

    expect($suscripcion->refresh()->status)->toBe(SubscriptionStatus::Suspended)
        ->and($suscripcion->notes)->toBe('Falta de pago desde marzo');
});

it('emite la factura del período desde el panel', function () {
    ['subscription' => $suscripcion] = altaDePrueba($this);

    Livewire::actingAs($this->superadmin)
        ->test(TenantIndex::class)
        ->call('confirm', $suscripcion->id, 'renew')
        ->call('apply')
        ->assertHasNoErrors();

    expect(SubscriptionInvoice::query()->where('subscription_id', $suscripcion->id)->count())->toBe(1);
});

it('registra el cobro de la factura y deja la cuenta al día', function () {
    ['subscription' => $suscripcion] = altaDePrueba($this);

    $factura = app(SubscriptionService::class)->renew($suscripcion, '2026-03-01');

    expect($suscripcion->refresh()->status)->toBe(SubscriptionStatus::PastDue);

    Livewire::actingAs($this->superadmin)
        ->test(TenantIndex::class)
        ->call('confirm', $suscripcion->id, 'pay')
        ->set('paymentReference', 'TRF-9001')
        ->call('pay', $factura->id)
        ->assertHasNoErrors()
        // Sin nada más pendiente, el diálogo se cierra solo.
        ->assertSet('actingOn', null);

    $factura->refresh();

    expect($factura->status)->toBe(InvoiceStatus::Paid)
        ->and($factura->payment_reference)->toBe('TRF-9001')
        ->and($suscripcion->refresh()->status)->toBe(SubscriptionStatus::Active);
});

it('no deja cobrar dos veces la misma factura', function () {
    ['subscription' => $suscripcion] = altaDePrueba($this);

    $factura = app(SubscriptionService::class)->renew($suscripcion, '2026-03-01');
    app(SubscriptionService::class)->recordPayment($factura, 'TRF-1');

    Livewire::actingAs($this->superadmin)
        ->test(TenantIndex::class)
        ->call('confirm', $suscripcion->id, 'pay')
        ->call('pay', $factura->id);
})->throws(ModelNotFoundException::class);

it('cambia de plan sin tocar el período en curso', function () {
    ['subscription' => $suscripcion] = altaDePrueba($this, 'emprende');
    $corporativo = Plan::query()->where('code', 'corporativo')->firstOrFail();

    $finAnterior = $suscripcion->current_period_end;

    Livewire::actingAs($this->superadmin)
        ->test(TenantIndex::class)
        ->call('confirm', $suscripcion->id, 'change_plan')
        ->set('newPlanId', $corporativo->id)
        ->call('apply')
        ->assertHasNoErrors();

    $suscripcion->refresh();

    expect($suscripcion->plan_id)->toBe($corporativo->id)
        ->and($suscripcion->max_companies)->toBe($corporativo->max_companies)
        ->and($suscripcion->current_period_end->toDateString())->toBe($finAnterior->toDateString());
});

it('avisa en pantalla cuando la operación no procede', function () {
    ['subscription' => $suscripcion] = altaDePrueba($this);

    app(SubscriptionService::class)
        ->cancel($suscripcion, 'Cerró el negocio');

    // Una cancelada ya no se puede renovar: el servicio lo impide, y el panel
    // tiene que decirlo en el propio diálogo en vez de reventar o de cerrarse
    // como si hubiera funcionado.
    Livewire::actingAs($this->superadmin)
        ->test(TenantIndex::class)
        ->call('confirm', $suscripcion->id, 'renew')
        ->call('apply')
        ->assertHasErrors('action')
        ->assertSet('actingOn', $suscripcion->id);

    expect(SubscriptionInvoice::query()->count())->toBe(0);
});
