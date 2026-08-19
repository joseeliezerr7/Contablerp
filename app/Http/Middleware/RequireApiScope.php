<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Api\Data\ApiScope;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un alcance en el token **y** el permiso equivalente en su dueño.
 *
 * Las dos condiciones, no una. El alcance acota lo que el programa puede pedir;
 * el permiso dice lo que la persona detrás del token puede hacer. Un token con
 * `sales:write` emitido por alguien que no puede facturar no factura: si solo
 * mirara el alcance, la API sería una puerta trasera para saltarse los roles que
 * la aplicación respeta.
 */
class RequireApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token === null || ! $token->can($scope)) {
            return $this->deny(sprintf(
                'El token no tiene el alcance «%s». Emitilo de nuevo con ese permiso.',
                $scope,
            ));
        }

        $permission = ApiScope::requiredPermissions()[$scope] ?? null;

        if ($permission !== null && ! $user->can($permission)) {
            return $this->deny(sprintf(
                'El usuario dueño del token no tiene permiso para %s en esta empresa.',
                mb_strtolower(ApiScope::all()[$scope] ?? $scope),
            ));
        }

        return $next($request);
    }

    private function deny(string $message): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'forbidden', 'message' => $message],
        ], 403);
    }
}
