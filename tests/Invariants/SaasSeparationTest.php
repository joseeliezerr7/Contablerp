<?php

declare(strict_types=1);

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\JournalEntryLine;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Billing\Services\SignupService;
use App\Domains\Billing\Services\SubscriptionService;
use App\Domains\Tenancy\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Invariante: **el cobro del servicio no es contabilidad del
 * cliente.**
 *
 * Lo que el proveedor le cobra al cliente por usar el sistema es ingreso del
 * proveedor, y el proveedor no lleva su contabilidad aquí. Si una factura de
 * suscripción generara partida en el libro del cliente, le metería un gasto que
 * él nunca capturó y le cambiaría la utilidad.
 *
 * Es una separación fácil de romper sin darse cuenta —basta con que alguien
 * "aproveche" el motor contable que ya está ahí—, y esta prueba es lo que avisa.
 */
beforeEach(function () {
    $this->seed(PlanSeeder::class);

    $this->signup = app(SignupService::class);
    $this->subscriptions = app(SubscriptionService::class);
});

/**
 * Da de alta dos cuentas independientes y las hace pasar por todo el ciclo de
 * cobro: prueba, activación, renovación, factura, pago, suspensión y cancelación.
 *
 * @return array{0: Company, 1: Company}
 */
function exerciseEveryBillingPath(object $ctx): array
{
    $emprende = Plan::query()->where('code', 'emprende')->firstOrFail();
    $corporativo = Plan::query()->where('code', 'corporativo')->firstOrFail();

    ['company' => $primera, 'subscription' => $unaSuscripcion] = $ctx->signup->register([
        'name' => 'Dueña',
        'email' => 'duena@primera.hn',
        'password' => 'una-contraseña-larga',
        'legal_name' => 'Primera Empresa, S.A.',
        'tax_id' => '08019011111111',
    ], $emprende);

    ['company' => $segunda, 'subscription' => $otraSuscripcion] = $ctx->signup->register([
        'name' => 'Dueño',
        'email' => 'dueno@segunda.hn',
        'password' => 'una-contraseña-larga',
        'legal_name' => 'Segunda Empresa, S.A.',
        'tax_id' => '05019022222222',
    ], $corporativo);

    // Ciclo completo de la primera.
    $ctx->subscriptions->activate($unaSuscripcion);
    $factura = $ctx->subscriptions->renew($unaSuscripcion->refresh(), '2026-03-01');
    $ctx->subscriptions->recordPayment($factura, 'TRF-9001');
    $ctx->subscriptions->renew($unaSuscripcion->refresh(), '2026-04-01');

    // La segunda cambia de plan, se suspende y se cancela.
    $ctx->subscriptions->changePlan($otraSuscripcion, $emprende);
    $ctx->subscriptions->suspend($otraSuscripcion->refresh(), 'Falta de pago');
    $ctx->subscriptions->cancel($otraSuscripcion->refresh(), 'Cerró el negocio');

    return [$primera, $segunda];
}

it('no genera ninguna partida contable al cobrar el servicio', function () {
    [$primera, $segunda] = exerciseEveryBillingPath($this);

    // Hubo facturas del servicio de verdad.
    expect(SubscriptionInvoice::query()->count())->toBeGreaterThan(0);

    // Y ni una sola partida en ningún libro.
    $partidas = JournalEntry::acrossCompanies()->count();

    expect($partidas)->toBe(
        0,
        'El cobro del servicio generó partidas contables en el libro de algún cliente.'
    );
});

it('deja el libro del cliente exactamente como estaba', function () {
    $emprende = Plan::query()->where('code', 'emprende')->firstOrFail();

    ['company' => $company, 'subscription' => $subscription] = $this->signup->register([
        'name' => 'Dueña',
        'email' => 'duena@negocio.hn',
        'password' => 'una-contraseña-larga',
        'legal_name' => 'Negocio, S.A.',
        'tax_id' => '08019011111111',
    ], $emprende);

    // Una partida propia del cliente, para que el libro no esté vacío.
    app(CompanyContext::class)->runFor($company, function (): void {
        app(AccountingEngine::class)->post(
            JournalDraft::on('2026-02-01', 'Aporte de capital')
                ->debit(account('1.1.02.01')->id, '50000.00')
                ->credit(account('3.1.01')->id, '50000.00')
        );
    });

    $antes = JournalEntryLine::acrossCompanies()->count();

    $factura = $this->subscriptions->renew($subscription, '2026-03-01');
    $this->subscriptions->recordPayment($factura, 'TRF-1');
    $this->subscriptions->suspend($subscription->refresh(), 'Prueba');

    expect(JournalEntryLine::acrossCompanies()->count())->toBe($antes)
        ->and(JournalEntry::acrossCompanies()->count())->toBe(1);
});

it('no mezcla el importe del servicio con ninguna cuenta del cliente', function () {
    exerciseEveryBillingPath($this);

    // Ninguna cuenta contable de ningún cliente tiene movimiento.
    $conMovimiento = DB::table('journal_entry_lines')->count();

    expect($conMovimiento)->toBe(0);
});

it('mantiene separadas las cuentas de dos clientes distintos', function () {
    [$primera, $segunda] = exerciseEveryBillingPath($this);

    expect($primera->tenant_id)->not->toBe($segunda->tenant_id);

    // Cada tenant ve solo su suscripción y sus facturas.
    $suscripciones = Subscription::query()->where('tenant_id', $primera->tenant_id)->count();
    $facturas = SubscriptionInvoice::query()->where('tenant_id', $primera->tenant_id)->count();

    expect($suscripciones)->toBe(1)
        ->and($facturas)->toBe(2)
        ->and(SubscriptionInvoice::query()->where('tenant_id', $segunda->tenant_id)->count())->toBe(0);
});

it('cuenta el ingreso recurrente solo de las cuentas que pagan', function () {
    exerciseEveryBillingPath($this);

    // La primera quedó activa o con pago pendiente; la segunda, cancelada.
    $recurrente = Subscription::query()->billable()->get()
        ->sum(fn (Subscription $s) => (float) $s->monthlyPrice()->toString());

    expect($recurrente)->toBe(450.0)
        ->and(Subscription::query()->live()->count())->toBe(1);
});

it('deja al superadministrador fuera de toda empresa', function () {
    $soporte = User::query()->create([
        'name' => 'Soporte',
        'email' => 'soporte@proveedor.hn',
        'password' => 'x',
        'is_super_admin' => true,
    ]);

    expect($soporte->isSuperAdmin())->toBeTrue()
        ->and($soporte->tenant_id)->toBeNull()
        ->and($soporte->companies()->count())->toBe(0);
});
