<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Tenancy\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

class SwitchCompanyController extends Controller
{
    /**
     * El route model binding resuelve la empresa sin filtrar por pertenencia
     * (Company no tiene scope global), así que la policy es obligatoria aquí:
     * es lo único que impide activar la empresa de otro tenant por su id.
     */
    public function __invoke(Request $request, Company $company): RedirectResponse
    {
        abort_unless($request->user()->can('switchTo', $company), 404);

        $request->session()->put('current_company_id', $company->id);
        $request->session()->forget('current_branch_id');

        // Los permisos cacheados pertenecen a la empresa anterior.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('dashboard')
            ->with('success', "Empresa activa: {$company->displayName()}");
    }
}
