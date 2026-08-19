<?php

declare(strict_types=1);

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Services\SignupService;
use App\Livewire\Admin\PlanIndex;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Livewire\Livewire;

/**
 * La pantalla de planes del proveedor.
 *
 * Era la última pieza del negocio propio sin pantalla: cambiar tu precio o crear
 * un plan exigía entrar a MySQL. Lo delicado no es el formulario sino dos
 * fronteras: que solo el superadministrador la vea, y que **editar un plan no
 * cambie el contrato de quien ya lo tiene** —la suscripción copia sus límites al
 * contratarse—.
 */
beforeEach(function () {
    $this->seed(PlanSeeder::class);

    $this->superadmin = User::query()->create([
        'name' => 'Soporte',
        'email' => 'soporte@proveedor.hn',
        'password' => bcrypt('secreto-largo'),
        'is_super_admin' => true,
    ]);

    $this->actingAs($this->superadmin);
});

it('lista los planes con su precio y sus suscripciones', function () {
    $this->get(route('admin.plans.index'))
        ->assertOk()
        ->assertSee('Planes del servicio');

    $planes = Livewire::test(PlanIndex::class)->viewData('plans');

    expect($planes->count())->toBe(Plan::query()->count());
});

it('crea un plan nuevo con límites y módulos', function () {
    Livewire::test(PlanIndex::class)
        ->call('create')
        ->set('code', 'corporativo-plus')
        ->set('name', 'Corporativo Plus')
        ->set('price', '2500.00')
        ->set('interval', 'monthly')
        ->set('trial_days', '15')
        ->set('max_companies', '10')
        ->set('max_users', '')
        ->set('has_multi_company', true)
        ->call('save')
        ->assertHasNoErrors();

    $plan = Plan::query()->where('code', 'corporativo-plus')->firstOrFail();

    expect($plan->priceAmount()->format())->toBe('2,500.00')
        ->and($plan->max_companies)->toBe(10)
        // Vacío se guarda como NULL: sin límite, como lo leen las cuotas.
        ->and($plan->max_users)->toBeNull()
        ->and($plan->has_multi_company)->toBeTrue();
});

it('no admite dos planes con el mismo código', function () {
    Livewire::test(PlanIndex::class)
        ->call('create')
        ->set('code', 'emprende')
        ->set('name', 'Duplicado')
        ->set('price', '0')
        ->call('save')
        ->assertHasErrors('code');
});

it('editar el plan no toca la suscripción que ya lo contrató', function () {
    $plan = Plan::query()->where('code', 'emprende')->firstOrFail();

    ['subscription' => $subscription] = app(SignupService::class)->register([
        'name' => 'Dueña',
        'email' => 'duena@negocio.hn',
        'password' => 'una-contraseña-larga',
        'legal_name' => 'Negocio de Prueba, S.A.',
        'tax_id' => '08019011111111',
    ], $plan);

    $usuariosContratados = $subscription->max_users;

    // El proveedor endurece el plan después.
    $this->actingAs($this->superadmin);

    Livewire::test(PlanIndex::class)
        ->call('edit', $plan->id)
        ->set('max_users', '1')
        ->call('save')
        ->assertHasNoErrors();

    // El catálogo cambió; el contrato de la suscripción, no.
    expect($plan->refresh()->max_users)->toBe(1)
        ->and($subscription->refresh()->max_users)->toBe($usuariosContratados)
        ->and($usuariosContratados)->not->toBe(1);
});

it('retira el plan sin borrarlo', function () {
    $plan = Plan::query()->where('code', 'emprende')->firstOrFail();

    Livewire::test(PlanIndex::class)->call('toggleActive', $plan->id);

    // Retirado deja de ofrecerse en el registro, pero sigue existiendo: las
    // suscripciones históricas lo referencian.
    expect($plan->refresh()->is_active)->toBeFalse()
        ->and(Plan::query()->whereKey($plan->id)->exists())->toBeTrue()
        ->and(Plan::query()->public()->where('code', 'emprende')->exists())->toBeFalse();
});

it('le niega la pantalla a un cliente', function () {
    ['user' => $cliente] = app(SignupService::class)->register([
        'name' => 'Dueña',
        'email' => 'duena@negocio.hn',
        'password' => 'una-contraseña-larga',
        'legal_name' => 'Negocio de Prueba, S.A.',
        'tax_id' => '08019011111111',
    ], Plan::query()->where('code', 'emprende')->firstOrFail());

    $this->actingAs($cliente)->get(route('admin.plans.index'))->assertForbidden();
});
