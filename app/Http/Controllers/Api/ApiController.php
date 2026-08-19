<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Base de los controladores de la API.
 *
 * Fija dos cosas para toda la superficie pública: cómo se pagina y cómo se ve un
 * error. Que cada endpoint invente su formato es lo que obliga a quien integra a
 * escribir un caso especial por ruta.
 */
abstract class ApiController extends Controller
{
    protected const MAX_PER_PAGE = 100;

    protected const DEFAULT_PER_PAGE = 25;

    /**
     * Cuántos registros por página, acotado.
     *
     * El techo no es una molestia: sin él, un `?per_page=100000` deja el
     * servidor armando una respuesta de cien megabytes mientras los demás
     * esperan.
     */
    protected function perPage(Request $request): int
    {
        $requested = (int) $request->query('per_page', (string) self::DEFAULT_PER_PAGE);

        return max(1, min($requested, self::MAX_PER_PAGE));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function fail(string $message, int $status = 422, string $code = 'invalid_request', array $extra = []): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message, ...$extra],
        ], $status);
    }
}
