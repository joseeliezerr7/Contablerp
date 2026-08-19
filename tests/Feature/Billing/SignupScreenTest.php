<?php

declare(strict_types=1);

use App\Domains\Accounting\Models\Account;
use App\Domains\Billing\Enums\SubscriptionStatus;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Tenancy\Models\Company;
use App\Livewire\Billing\SignupForm;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Livewire\Livewire;

/**
 * La pantalla de alta.
 *
 * Es la primera y la única impresión: quien llega aquí todavía no es cliente. Si
 * el formulario falla a medias y deja un usuario sin empresa, esa persona no
 * vuelve a intentarlo.
 */
beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function datosDeAlta(array $overrides = []): array
{
    return array_merge([
        'name' => 'María Funez',
        'email' => 'maria@ferreteria.hn',
        'password' => 'una-contraseña-larga',
        'password_confirmation' => 'una-contraseña-larga',
        'legal_name' => 'Ferretería Funez, S. de R.L.',
        'trade_name' => 'Ferretería Funez',
        'tax_id' => '08019011111111',
        'phone' => '9988-7766',
        'accepted' => true,
    ], $overrides);
}

it('está abierta al público', function () {
    $this->get('/registro')->assertOk()->assertSee('Emprende');
});

it('crea la empresa lista para operar y deja al dueño dentro', function () {
    $componente = Livewire::test(SignupForm::class)->set('planCode', 'emprende');

    foreach (datosDeAlta() as $campo => $valor) {
        $componente->set($campo, $valor);
    }

    $componente->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $usuario = User::query()->where('email', 'maria@ferreteria.hn')->firstOrFail();
    $empresa = Company::query()->where('tenant_id', $usuario->tenant_id)->firstOrFail();

    expect(auth()->id())->toBe($usuario->id)
        ->and($empresa->legal_name)->toBe('Ferretería Funez, S. de R.L.')
        // Con plan de cuentas: puede facturar hoy, no cuando alguien se lo cargue.
        ->and(Account::acrossCompanies()->where('company_id', $empresa->id)->count())->toBeGreaterThan(50);

    $suscripcion = Subscription::query()->where('tenant_id', $usuario->tenant_id)->firstOrFail();

    expect($suscripcion->status)->toBe(SubscriptionStatus::Trialing)
        ->and($suscripcion->plan->code)->toBe('emprende');
});

it('exige los datos mínimos para poder facturar', function () {
    Livewire::test(SignupForm::class)
        ->call('register')
        ->assertHasErrors([
            'name' => 'required',
            'email' => 'required',
            'password' => 'required',
            'legal_name' => 'required',
            'tax_id' => 'required',
            'accepted' => 'accepted',
        ]);
});

it('no acepta una contraseña que no coincide', function () {
    Livewire::test(SignupForm::class)
        ->set(datosDeAlta(['password_confirmation' => 'otra-cosa']))
        ->call('register')
        ->assertHasErrors(['password' => 'confirmed']);
});

it('rechaza un correo ya registrado sin dejar nada a medias', function () {
    User::query()->create([
        'name' => 'Alguien',
        'email' => 'maria@ferreteria.hn',
        'password' => bcrypt('x'),
    ]);

    Livewire::test(SignupForm::class)
        ->set(datosDeAlta())
        ->call('register')
        ->assertHasErrors('email');

    expect(Company::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0);
});

it('no deja elegir un plan que no está a la venta', function () {
    Plan::query()->where('code', 'corporativo')->update(['is_public' => false]);

    Livewire::test(SignupForm::class)
        ->set(datosDeAlta(['planCode' => 'corporativo']))
        ->call('register')
        ->assertHasErrors('planCode');

    expect(User::query()->where('email', 'maria@ferreteria.hn')->exists())->toBeFalse();
});

it('preselecciona el plan que venía en el enlace', function () {
    Livewire::withQueryParams(['plan' => 'negocio'])
        ->test(SignupForm::class)
        ->assertSet('planCode', 'negocio');
});
