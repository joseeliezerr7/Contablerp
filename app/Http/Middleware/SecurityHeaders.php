<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras que le dicen al navegador que no colabore con un ataque.
 *
 * Ninguna reemplaza a la validación del servidor; todas cierran una puerta que
 * el servidor no controla. Van en el grupo `web` y no en la API: un cliente de
 * API no es un navegador y estas cabeceras no le dicen nada.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Sin esto, un navegador puede «adivinar» que un adjunto subido por un
        // usuario es HTML y ejecutarlo en el dominio de la aplicación.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // La aplicación no se muestra dentro de un iframe ajeno, así que nadie
        // puede superponerle botones invisibles.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Al salir hacia otro sitio no se filtra en qué pantalla estaba el
        // usuario; el origen sí, que es lo que necesitan los servicios legítimos.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // El sistema no usa cámara, micrófono ni ubicación. Declararlo evita que
        // algo inyectado en la página pueda pedirlos.
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // HSTS solo cuando la petición ya llegó por HTTPS: mandarla por http
        // sobre un dominio sin certificado deja el sitio inaccesible durante el
        // tiempo del max-age, y eso no se puede deshacer desde el servidor.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
