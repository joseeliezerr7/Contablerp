<?php

declare(strict_types=1);

use App\Domains\Identity\Data\PermissionCatalog;

/**
 * Endurecimiento: lo que protege al sistema fuera de la lógica de negocio.
 *
 * Son comprobaciones aburridas y por eso mismo se olvidan. Una instalación con
 * `APP_DEBUG=true` le enseña la contraseña de la base al primero que provoque
 * un error 500, y nadie se entera hasta que pasa.
 */

/*
|--------------------------------------------------------------------------
| Cabeceras de seguridad
|--------------------------------------------------------------------------
*/

it('manda las cabeceras de seguridad en las pantallas', function () {
    $company = accountingCompany();
    actingAsUserOf($company, role: PermissionCatalog::ADMIN);

    $response = $this->get(route('dashboard'));

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($response->headers->get('Permissions-Policy'))->toContain('camera=()');
});

it('no manda HSTS sobre http', function () {
    // Mandar HSTS por http deja el sitio inaccesible durante el max-age si el
    // dominio todavía no tiene certificado, y eso no se puede deshacer desde
    // el servidor: el navegador ya lo recordó.
    $company = accountingCompany();
    actingAsUserOf($company, role: PermissionCatalog::ADMIN);

    $this->get(route('dashboard'))->assertHeaderMissing('Strict-Transport-Security');
});

it('manda HSTS sobre https', function () {
    $company = accountingCompany();
    actingAsUserOf($company, role: PermissionCatalog::ADMIN);

    $this->get('https://localhost/dashboard')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

/*
|--------------------------------------------------------------------------
| La revisión previa a entregar
|--------------------------------------------------------------------------
*/

it('se niega cuando APP_DEBUG está encendido', function () {
    config(['app.debug' => true]);

    $this->artisan('contable:check-produccion')
        ->expectsOutputToContain('APP_DEBUG apagado')
        ->assertExitCode(1);
});

it('se niega cuando la aplicación entra a la base como root', function () {
    productionConfig();
    config(['database.connections.'.config('database.default').'.username' => 'root']);

    $this->artisan('contable:check-produccion')
        ->expectsOutputToContain('no root')
        ->assertExitCode(1);
});

it('se niega cuando APP_URL no lleva HTTPS', function () {
    productionConfig();
    config(['app.url' => 'http://contable.hn']);

    $this->artisan('contable:check-produccion')->assertExitCode(1);
});

it('pasa con una configuración de producción', function () {
    // Los permisos se siembran al crear la empresa; sin ninguna, el catálogo
    // está vacío y la comprobación de permisos falla con razón.
    accountingCompany();

    productionConfig();

    $this->artisan('contable:check-produccion')->assertExitCode(0);
});

it('con --strict las advertencias también paran el despliegue', function () {
    accountingCompany();
    productionConfig();

    // El correo en «log» es advertencia: nadie podría recuperar su contraseña.
    config(['mail.default' => 'log']);

    $this->artisan('contable:check-produccion --strict')->assertExitCode(1);
});

/*
|--------------------------------------------------------------------------
| Respaldo
|--------------------------------------------------------------------------
*/

it('no intenta respaldar una base que no es MySQL', function () {
    // Sin tocar la conexión predeterminada: cambiarla dejaría a la propia
    // prueba sin base donde deshacer sus cambios al terminar.
    $this->artisan('db:backup --connection=sqlite')
        ->expectsOutputToContain('Solo se respalda MySQL')
        ->assertExitCode(1);
});

it('avisa cuando la conexión no existe', function () {
    $this->artisan('db:backup --connection=inventada')
        ->expectsOutputToContain('No existe la conexión')
        ->assertExitCode(1);
});

/**
 * Deja la configuración como quedaría en el servidor, salvo lo que cada prueba
 * rompe a propósito.
 */
function productionConfig(): void
{
    app()->detectEnvironment(fn () => 'production');

    config([
        'app.debug' => false,
        'app.url' => 'https://contable.hn',
        'mail.default' => 'smtp',
        'logging.channels.single.level' => 'warning',
        'database.connections.'.config('database.default').'.username' => 'contable',
        'database.connections.'.config('database.default').'.password' => 'una-clave-larga',
    ]);
}
