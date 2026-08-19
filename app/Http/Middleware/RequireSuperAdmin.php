<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Acceso al panel del proveedor.
 *
 * Va **en lugar** del middleware de empresa, no además de él: un
 * superadministrador no pertenece a ninguna empresa, y exigirle una lo mandaría
 * a la pantalla de «no tienes empresa asignada».
 */
class RequireSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isSuperAdmin()) {
            abort(403, 'Esta área es del proveedor del servicio.');
        }

        return $next($request);
    }
}
