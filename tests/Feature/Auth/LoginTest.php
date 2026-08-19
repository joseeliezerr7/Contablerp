<?php

declare(strict_types=1);

use App\Domains\Tenancy\Enums\TenantStatus;
use App\Domains\Tenancy\Models\Company;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;

it('muestra la pantalla de inicio de sesión', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Iniciar sesión');
});

it('autentica con credenciales válidas y activa su empresa', function () {
    $company = companyWithBranch();
    $user = User::factory()->forCompany($company)->create(['email' => 'contador@empresa.test']);

    $this->post('/login', [
        'email' => 'contador@empresa.test',
        'password' => 'password',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);

    $this->get('/dashboard')->assertOk()->assertSee($company->displayName());
});

it('rechaza credenciales inválidas', function () {
    $company = companyWithBranch();
    User::factory()->forCompany($company)->create(['email' => 'contador@empresa.test']);

    $this->post('/login', [
        'email' => 'contador@empresa.test',
        'password' => 'incorrecta',
    ])->assertSessionHasErrors();

    $this->assertGuest();
});

it('no autentica a un usuario desactivado aunque la contraseña sea correcta', function () {
    $company = companyWithBranch();
    User::factory()->forCompany($company)->inactive()->create(['email' => 'baja@empresa.test']);

    $this->post('/login', [
        'email' => 'baja@empresa.test',
        'password' => 'password',
    ])->assertSessionHasErrors();

    $this->assertGuest();
});

it('no autentica si la cuenta SaaS está suspendida', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);
    $company = Company::factory()
        ->withMainBranch()
        ->create(['tenant_id' => $tenant->id]);

    User::factory()->forCompany($company)->create(['email' => 'suspendido@empresa.test']);

    $this->post('/login', [
        'email' => 'suspendido@empresa.test',
        'password' => 'password',
    ])->assertSessionHasErrors();

    $this->assertGuest();
});

it('registra la fecha e IP del último acceso', function () {
    $company = companyWithBranch();
    $user = User::factory()->forCompany($company)->create(['email' => 'acceso@empresa.test']);

    expect($user->last_login_at)->toBeNull();

    $this->post('/login', [
        'email' => 'acceso@empresa.test',
        'password' => 'password',
    ]);

    expect($user->refresh()->last_login_at)->not->toBeNull()
        ->and($user->last_login_ip)->not->toBeNull();
});

it('envía a la pantalla de sin empresa cuando el usuario no tiene ninguna', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect(route('companies.none'));
});

it('bloquea el acceso al dashboard sin autenticar', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('limita los intentos de inicio de sesión a 5 por minuto', function () {
    $company = companyWithBranch();
    User::factory()->forCompany($company)->create(['email' => 'fuerza@empresa.test']);

    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => 'fuerza@empresa.test', 'password' => 'mala'])
            ->assertStatus(302);
    }

    // El middleware `throttle:login` de Fortify corta con 429, no con un
    // error de validación.
    $this->post('/login', ['email' => 'fuerza@empresa.test', 'password' => 'mala'])
        ->assertStatus(429);

    $this->assertGuest();
});
