<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Api\Models\ApiToken;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activa la empresa del token para el resto de la petición.
 *
 * Es el equivalente de `SetCurrentCompany` para la API, y su punto más
 * importante es el mismo: la empresa **no** sale de nada que mande el cliente.
 * En la web sale de la sesión resuelta contra las empresas del usuario; aquí
 * sale del token, que es un secreto que el servidor emitió. Un parámetro
 * `?company=` sería una invitación a leer la contabilidad del vecino.
 *
 * Comprueba, en este orden:
 *
 *  1. Que el token no esté revocado ni vencido —Sanctum solo mira `expires_at`—.
 *  2. Que traiga empresa.
 *  3. Que el usuario dueño siga activo y siga perteneciendo a esa empresa. Es lo
 *     que hace que dar de baja a un empleado corte también sus integraciones,
 *     sin tener que acordarse de revocar tokens uno por uno.
 *  4. Que la cuenta SaaS del tenant permita el acceso.
 */
class SetCompanyFromToken
{
    public function __construct(private readonly CompanyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($user === null || ! $token instanceof ApiToken) {
            return $this->deny('No se pudo autenticar el token.', 401);
        }

        if (! $token->isUsable()) {
            return $this->deny(
                $token->isRevoked()
                    ? 'Este token fue revocado.'
                    : 'Este token venció el '.$token->expires_at->format('d/m/Y').'.',
                401,
            );
        }

        if ($token->company_id === null) {
            return $this->deny(
                'El token no tiene empresa asignada. Emitilo de nuevo desde la pantalla de tokens.',
                403,
            );
        }

        if (! $user->is_active) {
            return $this->deny('El usuario dueño del token está desactivado.', 403);
        }

        $company = $user->companies()->whereKey($token->company_id)->first();

        if ($company === null) {
            return $this->deny(
                'El usuario dueño del token ya no tiene acceso a esa empresa.',
                403,
            );
        }

        if ($user->tenant !== null && ! $user->tenant->allowsAccess()) {
            return $this->deny('La cuenta se encuentra suspendida.', 403);
        }

        $this->context->set($company);

        // Los roles de spatie están particionados por empresa. Sin esto, un
        // `$user->can()` dentro de la API consultaría los permisos de otra.
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

        // Rastro de uso: sin la IP no hay forma de responder «¿desde dónde se
        // está usando este token?» cuando alguien sospecha.
        $token->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
        ])->save();

        return $next($request);
    }

    private function deny(string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $status === 401 ? 'unauthenticated' : 'forbidden',
                'message' => $message,
            ],
        ], $status);
    }
}
