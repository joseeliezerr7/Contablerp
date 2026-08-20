<?php

declare(strict_types=1);

use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\AccountingPeriod;
use App\Domains\Billing\Enums\SubscriptionStatus;
use App\Domains\Billing\Exceptions\BillingException;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Services\SignupService;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Tenancy\Enums\TenantStatus;
use App\Domains\Tenancy\Models\Company;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Database\Seeders\PlanSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Criterio de aceptación: **alta self-service de una empresa nueva**.
 *
 * De un formulario público a una empresa que puede emitir su primera factura,
 * en una sola transacción. Un alta a medias deja a alguien registrado que no
 * puede trabajar, y esa persona no vuelve.
 */
beforeEach(function () {
    $this->seed(PlanSeeder::class);

    $this->signup = app(SignupService::class);
    $this->plan = Plan::query()->where('code', 'negocio')->firstOrFail();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function signupData(array $overrides = []): array
{
    return [
        'name' => 'María Elena Fúnez',
        'email' => 'maria@ferreteriafunez.hn',
        'password' => 'una-contraseña-larga',
        'legal_name' => 'Ferretería Fúnez, S. de R.L.',
        'trade_name' => 'Ferretería Fúnez',
        'tax_id' => '08019012345678',
        'phone' => '2550-1234',
        ...$overrides,
    ];
}

it('deja la empresa lista para operar', function () {
    ['company' => $company, 'user' => $user] = $this->signup->register(signupData(), $this->plan);

    app(CompanyContext::class)->runFor($company, function () use ($company): void {
        expect(Account::query()->count())->toBeGreaterThan(50)
            ->and(AccountingPeriod::query()->count())->toBe(12)
            ->and($company->branches()->count())->toBe(1)
            ->and($company->warehouses()->count())->toBe(1)
            // Los impuestos y las listas de precios también quedan sembrados.
            ->and(Tax::query()->count())->toBeGreaterThan(0);
    });

    expect($user->companies()->count())->toBe(1);
});

it('deja al usuario como administrador de su empresa', function () {
    ['company' => $company, 'user' => $user] = $this->signup->register(signupData(), $this->plan);

    app(CompanyContext::class)->set($company);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    $user->load('roles');

    expect($user->hasRole(PermissionCatalog::ADMIN))->toBeTrue();
});

it('arranca la cuenta en período de prueba', function () {
    ['tenant' => $tenant, 'subscription' => $subscription] = $this->signup->register(signupData(), $this->plan);

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($tenant->status)->toBe(TenantStatus::Trial)
        ->and($subscription->trialDaysLeft())->toBe(30)
        // El precio y los límites quedan copiados del plan: son el contrato.
        ->and($subscription->priceAmount()->toString())->toBe('1200.0000')
        ->and($subscription->max_users)->toBe(10);
});

it('rechaza un correo ya registrado', function () {
    $this->signup->register(signupData(), $this->plan);

    expect(fn () => $this->signup->register(signupData(), $this->plan))
        ->toThrow(BillingException::class, 'Ya existe una cuenta');
});

it('no deja nada a medias si el alta falla', function () {
    // Un RTN imposible hace fallar la creación de la empresa **después** de
    // haber creado usuario, cuenta y suscripción. Si no fuera todo una
    // transacción, quedaría alguien registrado sin poder trabajar.
    try {
        $this->signup->register(signupData(['tax_id' => str_repeat('9', 500)]), $this->plan);
    } catch (Throwable) {
        // Esperado.
    }

    expect(User::query()->count())->toBe(0)
        ->and(Tenant::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0)
        ->and(Company::query()->count())->toBe(0);
});

it('genera un slug distinto para dos cuentas con el mismo nombre', function () {
    $this->signup->register(signupData(), $this->plan);

    ['tenant' => $segunda] = $this->signup->register(
        signupData(['email' => 'otro@ferreteriafunez.hn']),
        $this->plan,
    );

    expect($segunda->slug)->toBe('ferreteria-funez-s-de-rl-2');
});

it('aísla las dos cuentas recién creadas', function () {
    ['company' => $primera] = $this->signup->register(signupData(), $this->plan);

    ['company' => $segunda, 'user' => $otroUsuario] = $this->signup->register(
        signupData(['email' => 'otro@negocio.hn', 'legal_name' => 'Otro Negocio, S.A.', 'tax_id' => '05019098765432']),
        $this->plan,
    );

    expect($primera->tenant_id)->not->toBe($segunda->tenant_id)
        ->and($otroUsuario->belongsToCompany($primera->id))->toBeFalse()
        ->and($otroUsuario->belongsToCompany($segunda->id))->toBeTrue();
});
