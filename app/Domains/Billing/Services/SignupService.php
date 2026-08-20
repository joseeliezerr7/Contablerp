<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Exceptions\BillingException;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Tenancy\Enums\TenantStatus;
use App\Domains\Tenancy\Models\Company;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\CompanyService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Alta self-service: de un formulario público a una empresa lista para facturar.
 *
 * En **una sola transacción** se crean el
 * usuario, la cuenta, la suscripción de prueba y la empresa con todo lo que
 * necesita para operar —sucursal, bodega, plan de cuentas hondureño, ejercicio
 * fiscal con sus doce períodos, roles e impuestos—.
 *
 * Que sea una transacción no es un detalle técnico: un alta a medias deja a
 * alguien registrado que no puede emitir su primera factura, y esa persona no
 * vuelve. O nace todo, o no nace nada.
 */
final class SignupService
{
    public function __construct(
        private readonly CompanyService $companies,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{user: User, tenant: Tenant, company: Company, subscription: Subscription}
     */
    public function register(array $data, Plan $plan): array
    {
        $email = mb_strtolower(trim((string) $data['email']));

        if (User::query()->where('email', $email)->exists()) {
            throw BillingException::emailTaken($email);
        }

        if (! $plan->is_active) {
            throw BillingException::planUnavailable();
        }

        return DB::transaction(function () use ($data, $plan, $email): array {
            $companyName = trim((string) $data['legal_name']);

            $tenant = new Tenant;
            $tenant->forceFill([
                'name' => $companyName,
                'slug' => $this->uniqueSlug($companyName),
                'status' => TenantStatus::Trial,
            ])->save();

            $user = new User;
            $user->forceFill([
                'name' => trim((string) $data['name']),
                'email' => $email,
                'password' => Hash::make((string) $data['password']),
                'tenant_id' => $tenant->id,
                'is_active' => true,
            ])->save();

            // La suscripción antes que la empresa: el alta de la empresa
            // comprueba la cuota, y sin suscripción no habría límite que
            // comprobar.
            $subscription = $this->subscriptions->subscribe($tenant, $plan);

            $company = $this->companies->create([
                'legal_name' => $companyName,
                'trade_name' => $data['trade_name'] ?? null,
                'tax_id' => trim((string) $data['tax_id']),
                'email' => $email,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'currency_code' => 'HNL',
                'fiscal_year_start_month' => 1,
                'is_active' => true,
            ], $user);

            return [
                'user' => $user->refresh(),
                'tenant' => $tenant->refresh(),
                'company' => $company,
                'subscription' => $subscription->refresh(),
            ];
        });
    }

    /**
     * Slug único para la cuenta.
     *
     * Dos empresas pueden llamarse parecido, y el slug identifica la cuenta en
     * las URL del proveedor.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'cuenta';
        $slug = $base;
        $suffix = 2;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
