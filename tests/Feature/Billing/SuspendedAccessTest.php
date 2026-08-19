<?php

declare(strict_types=1);

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Services\SignupService;
use App\Domains\Billing\Services\SubscriptionService;
use Database\Seeders\PlanSeeder;

it('le corta el paso al usuario de una cuenta suspendida, con motivo visible', function () {
    $this->seed(PlanSeeder::class);

    ['user' => $user, 'subscription' => $subscription] = app(SignupService::class)->register([
        'name' => 'Duena',
        'email' => 'duena@negocio.hn',
        'password' => 'una-contrasena-larga',
        'legal_name' => 'Negocio, S.A.',
        'tax_id' => '08019011111111',
    ], Plan::query()->where('code', 'emprende')->firstOrFail());

    app(SubscriptionService::class)->suspend($subscription, 'Falta de pago');

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'La cuenta se encuentra suspendida.']);

    expect(auth()->check())->toBeFalse();
});
