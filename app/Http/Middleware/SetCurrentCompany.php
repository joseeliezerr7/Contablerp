<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activa la empresa del usuario para el resto de la petición.
 *
 * La empresa candidata sale de la sesión, pero SIEMPRE se vuelve a resolver
 * contra la relación companies() del usuario: una sesión manipulada no puede
 * activar una empresa ajena.
 */
class SetCurrentCompany
{
    public function __construct(private readonly CompanyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if (! $user->is_active) {
            return $this->reject($request, 'Tu usuario está desactivado. Contacta al administrador.');
        }

        if ($user->tenant !== null && ! $user->tenant->allowsAccess()) {
            return $this->reject($request, 'La cuenta se encuentra suspendida.');
        }

        // El superadministrador no tiene empresa, y mandarlo a «no tienes
        // empresa asignada» sería decirle que le falta algo. Le falta lo
        // contrario: su sitio es el panel del proveedor.
        if ($user->isSuperAdmin() && $user->companies()->count() === 0) {
            return redirect()->route('admin.tenants.index');
        }

        $company = $this->resolveCompany($request, $user);

        if ($company === null) {
            return redirect()->route('companies.none');
        }

        $this->context->set($company, $this->resolveBranch($request, $company));

        $request->session()->put('current_company_id', $company->id);
        $request->session()->put('current_branch_id', $this->context->branchId());

        // Los roles de spatie están particionados por empresa (teams).
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

        return $next($request);
    }

    private function resolveCompany(Request $request, $user): ?Company
    {
        $sessionCompanyId = $request->session()->get('current_company_id');

        if ($sessionCompanyId !== null) {
            $company = $user->companies()->whereKey($sessionCompanyId)->first();

            if ($company !== null && $company->is_active) {
                return $company;
            }

            $request->session()->forget('current_company_id');
        }

        return $user->resolveDefaultCompany();
    }

    private function resolveBranch(Request $request, Company $company): ?Branch
    {
        $branchId = $request->session()->get('current_branch_id')
            ?? $request->user()->default_branch_id;

        if ($branchId === null) {
            return null;
        }

        // El scope global ya está activo aquí, pero la empresa se acaba de fijar
        // en el contexto solo después; se filtra explícitamente por seguridad.
        return Branch::acrossCompanies()
            ->where('company_id', $company->id)
            ->whereKey($branchId)
            ->first();
    }

    private function reject(Request $request, string $message): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
