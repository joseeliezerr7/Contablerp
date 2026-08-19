<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Enums\SubscriptionStatus;
use App\Domains\Billing\Exceptions\BillingException;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Billing\Services\QuotaService;
use App\Domains\Billing\Services\SignupService;
use App\Domains\Billing\Services\SubscriptionService;
use App\Domains\Tenancy\Enums\TenantStatus;
use App\Domains\Tenancy\Models\Company;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\CompanyService;
use App\Models\User;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(PlanSeeder::class);

    $this->signup = app(SignupService::class);
    $this->subscriptions = app(SubscriptionService::class);
    $this->quotas = app(QuotaService::class);

    $this->plan = Plan::query()->where('code', 'emprende')->firstOrFail();
});

/**
 * Da de alta una cuenta y devuelve sus piezas.
 *
 * @return array{user: User, tenant: Tenant, company: Company, subscription: Subscription}
 */
function newAccount(object $ctx, ?Plan $plan = null, string $email = 'dueno@negocio.hn'): array
{
    return $ctx->signup->register([
        'name' => 'Dueño',
        'email' => $email,
        'password' => 'una-contraseña-larga',
        'legal_name' => 'Negocio de Prueba, S.A.',
        'tax_id' => '08019011111111',
    ], $plan ?? $ctx->plan);
}

/*
|--------------------------------------------------------------------------
| Cuotas
|--------------------------------------------------------------------------
*/

it('impide crear más empresas de las que permite el plan', function () {
    ['tenant' => $tenant, 'user' => $user] = newAccount($this);

    // El plan Emprende permite una sola empresa, que ya se creó al darse de alta.
    expect($this->quotas->remainingCompanies($tenant))->toBe(0)
        ->and($this->quotas->canAddCompany($tenant))->toBeFalse();

    expect(fn () => app(CompanyService::class)->create([
        'legal_name' => 'Segunda Empresa, S.A.',
        'tax_id' => '08019022222222',
        'currency_code' => 'HNL',
        'fiscal_year_start_month' => 1,
        'is_active' => true,
    ], $user))->toThrow(BillingException::class, 'permite hasta 1 empresas');
});

it('deja crear varias empresas con un plan sin límite', function () {
    $corporativo = Plan::query()->where('code', 'corporativo')->firstOrFail();

    ['tenant' => $tenant, 'user' => $user] = newAccount($this, $corporativo);

    expect($this->quotas->remainingCompanies($tenant))->toBeNull();

    app(CompanyService::class)->create([
        'legal_name' => 'Segunda Empresa, S.A.',
        'tax_id' => '08019022222222',
        'currency_code' => 'HNL',
        'fiscal_year_start_month' => 1,
        'is_active' => true,
    ], $user);

    expect($tenant->companies()->count())->toBe(2);
});

it('no bloquea nada cuando el tenant no tiene suscripción', function () {
    ['tenant' => $tenant, 'subscription' => $subscription] = newAccount($this);

    $this->subscriptions->cancel($subscription, 'Se dio de baja');

    // El cobro no debe impedir que la contabilidad funcione.
    expect($this->quotas->subscriptionFor($tenant->refresh()))->toBeNull()
        ->and($this->quotas->canAddCompany($tenant))->toBeTrue();
});

it('informa el consumo actual de la cuenta', function () {
    ['tenant' => $tenant] = newAccount($this);

    expect($this->quotas->usage($tenant))
        ->toMatchArray(['companies' => 1, 'users' => 1, 'branches' => 1]);
});

/*
|--------------------------------------------------------------------------
| Ciclo de vida
|--------------------------------------------------------------------------
*/

it('detecta las pruebas vencidas sin cortar el acceso', function () {
    ['subscription' => $subscription] = newAccount($this);

    $subscription->forceFill(['trial_ends_at' => now()->subDay()])->save();

    expect($this->subscriptions->expiredTrials())->toHaveCount(1)
        // Sigue pudiendo entrar: cortar el acceso es una decisión comercial.
        ->and($subscription->refresh()->allowsAccess())->toBeTrue();
});

it('activa la cuenta al terminar la prueba', function () {
    ['tenant' => $tenant, 'subscription' => $subscription] = newAccount($this);

    $activa = $this->subscriptions->activate($subscription);

    expect($activa->status)->toBe(SubscriptionStatus::Active)
        ->and($activa->trial_ends_at)->toBeNull()
        ->and($tenant->refresh()->status)->toBe(TenantStatus::Active);
});

it('emite la factura del servicio al renovar y deja al cliente trabajando', function () {
    ['subscription' => $subscription] = newAccount($this);

    $factura = $this->subscriptions->renew($subscription, '2026-02-01');

    expect($factura->number)->toBe('SUS-000001')
        ->and($factura->amountMoney()->toString())->toBe('450.0000')
        ->and($factura->status)->toBe(InvoiceStatus::Pending)
        // Debe una factura, pero no se le corta: eso se decide aparte.
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->allowsAccess())->toBeTrue();
});

it('avanza el período al renovar', function () {
    ['subscription' => $subscription] = newAccount($this);

    $anterior = $subscription->current_period_end->copy();

    $this->subscriptions->renew($subscription);

    expect($subscription->refresh()->current_period_start->toDateString())
        ->toBe($anterior->addDay()->toDateString());
});

it('vuelve a estar al día al cobrar la factura', function () {
    ['subscription' => $subscription] = newAccount($this);

    $factura = $this->subscriptions->renew($subscription);
    $this->subscriptions->recordPayment($factura, 'TRF-5501');

    expect($factura->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::Active);
});

it('sigue debiendo si queda otra factura pendiente', function () {
    ['subscription' => $subscription] = newAccount($this);

    $enero = $this->subscriptions->renew($subscription, '2026-02-01');
    $this->subscriptions->renew($subscription->refresh(), '2026-03-01');

    $this->subscriptions->recordPayment($enero, 'TRF-1');

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::PastDue);
});

it('suspende y corta el acceso', function () {
    ['tenant' => $tenant, 'subscription' => $subscription] = newAccount($this);

    $this->subscriptions->suspend($subscription, 'Tres meses sin pagar');

    expect($subscription->refresh()->allowsAccess())->toBeFalse()
        ->and($tenant->refresh()->status)->toBe(TenantStatus::Suspended)
        ->and($tenant->allowsAccess())->toBeFalse();
});

it('reactiva la cuenta suspendida', function () {
    ['tenant' => $tenant, 'subscription' => $subscription] = newAccount($this);

    $this->subscriptions->suspend($subscription, 'Falta de pago');
    $this->subscriptions->activate($subscription->refresh());

    expect($subscription->refresh()->allowsAccess())->toBeTrue()
        ->and($tenant->refresh()->status)->toBe(TenantStatus::Active);
});

it('impide dos suscripciones vivas en la misma cuenta', function () {
    ['tenant' => $tenant] = newAccount($this);

    expect(fn () => $this->subscriptions->subscribe($tenant, $this->plan))
        ->toThrow(BillingException::class, 'ya tiene una suscripción');
});

it('deja volver a suscribir después de cancelar', function () {
    ['tenant' => $tenant, 'subscription' => $subscription] = newAccount($this);

    $this->subscriptions->cancel($subscription, 'Cerró el negocio');
    $nueva = $this->subscriptions->subscribe($tenant->refresh(), $this->plan);

    expect($nueva->status)->toBe(SubscriptionStatus::Trialing);
});

it('cambia de plan sin prorratear', function () {
    ['subscription' => $subscription] = newAccount($this);

    $corporativo = Plan::query()->where('code', 'corporativo')->firstOrFail();
    $cambiada = $this->subscriptions->changePlan($subscription, $corporativo);

    expect($cambiada->priceAmount()->toString())->toBe('2900.0000')
        ->and($cambiada->max_companies)->toBeNull()
        // El período en curso no se toca: el precio nuevo entra en el siguiente.
        ->and($cambiada->current_period_end->toDateString())
        ->toBe($subscription->current_period_end->toDateString());
});

it('no admite cambios en una suscripción cancelada', function () {
    ['subscription' => $subscription] = newAccount($this);

    $this->subscriptions->cancel($subscription, 'Cerró');

    expect(fn () => $this->subscriptions->activate($subscription->refresh()))
        ->toThrow(BillingException::class, 'está cancelada');
});

it('cobra el plan anual sin distorsionar el ingreso mensual', function () {
    $anual = Plan::query()->create([
        'code' => 'anual',
        'name' => 'Anual',
        'price' => '12000.00',
        'currency_code' => 'HNL',
        'interval' => 'yearly',
        'trial_days' => 0,
        'is_public' => true,
        'is_active' => true,
    ]);

    ['subscription' => $subscription] = newAccount($this, $anual);

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->monthlyPrice()->toString())->toBe('1000.0000')
        // Un año menos un día.
        ->and($subscription->current_period_end->toDateString())
        ->toBe(now()->startOfDay()->addYear()->subDay()->toDateString());
});

it('no cobra un plan gratuito', function () {
    $gratis = Plan::query()->create([
        'code' => 'gratis',
        'name' => 'Gratuito',
        'price' => '0',
        'currency_code' => 'HNL',
        'interval' => 'monthly',
        'trial_days' => 0,
        'max_companies' => 1,
        'is_public' => true,
        'is_active' => true,
    ]);

    ['subscription' => $subscription] = newAccount($this, $gratis);

    $factura = $this->subscriptions->renew($subscription);

    expect($factura->status)->toBe(InvoiceStatus::Paid)
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(SubscriptionInvoice::query()->pending()->count())->toBe(0);
});
