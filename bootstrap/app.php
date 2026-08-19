<?php

use App\Http\Middleware\RequireApiScope;
use App\Http\Middleware\RequireSuperAdmin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCompanyFromToken;
use App\Http\Middleware\SetCurrentCompany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        // Versionada desde el primer día: `/api/v1`. Una API pública sin versión
        // no se puede cambiar nunca sin romperle el software a alguien.
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Los comandos viven en su dominio, fuera de app/Console/Commands, así que
    // el descubrimiento automático no los encuentra.
    ->withCommands([
        __DIR__.'/../app/Domains/Accounting/Console',
        __DIR__.'/../app/Domains/Fiscal/Console',
        __DIR__.'/../app/Domains/Identity/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Solo en 'web': un cliente de API no es un navegador y estas cabeceras
        // no le dicen nada.
        $middleware->web(append: [SecurityHeaders::class]);

        // Se ejecuta después de 'auth' para que exista usuario autenticado.
        $middleware->alias([
            'company' => SetCurrentCompany::class,
            // Va en lugar de 'company', no encima: el superadministrador no
            // pertenece a ninguna empresa.
            'superadmin' => RequireSuperAdmin::class,
            // El equivalente de 'company' para la API: la empresa sale del
            // token, nunca de algo que mande el cliente.
            'api.company' => SetCompanyFromToken::class,
            'api.scope' => RequireApiScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
