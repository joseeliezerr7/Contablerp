<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Api\Models\ApiToken;
use App\Http\Middleware\SetCurrentCompany;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Una sola instancia por petición: el scope global, el trait de aislamiento
        // y las reglas de validación deben ver exactamente el mismo estado.
        $this->app->singleton(CompanyContext::class);
    }

    public function boot(): void
    {
        // La ruta /livewire/update solo trae ['web', RequireLivewireHeaders]. Sin
        // esto, cada acción de un componente (guardar, editar, eliminar) corre sin
        // empresa activa y el scope global revienta o la policy deniega.
        Livewire::addPersistentMiddleware(SetCurrentCompany::class);

        // Sanctum resuelve los tokens con su propio modelo. El nuestro añade la
        // empresa sobre la que actúa el token y el estado de revocación, y sin
        // esta línea el middleware de la API recibiría un PersonalAccessToken
        // pelado y no podría fijar la empresa.
        Sanctum::usePersonalAccessTokenModel(ApiToken::class);

        // Límite de peticiones **por token**, no por IP: varios clientes detrás
        // del mismo NAT no deben estorbarse, y un token que se descontrola no
        // debe poder dejar fuera a los demás. Sin token identificable —una
        // petición sin autenticar— se cae a la IP, que es lo único que hay.
        RateLimiter::for('api', function (Request $request) {
            $token = $request->user()?->currentAccessToken();

            // `currentAccessToken()` también devuelve un `TransientToken` cuando
            // la sesión web pasa por Sanctum, y ese no tiene clave. Solo un token
            // real identifica a un integrador.
            return $token instanceof ApiToken
                ? Limit::perMinute(120)->by('token:'.$token->getKey())
                : Limit::perMinute(20)->by($request->ip());
        });

        // Fuera de producción, cualquier acceso a una relación no cargada, un
        // atributo inexistente o un atributo descartado en silencio es un error.
        //
        // Incluye el entorno de pruebas a propósito: con el modo estricto solo
        // en `local`, la suite pasaba en verde mientras la aplicación reventaba
        // al guardar, porque en pruebas los atributos no asignables se
        // descartaban sin avisar.
        Model::shouldBeStrict(! $this->app->isProduction());

        // El motor contable depende de transacciones: una consulta lenta dentro
        // de una transacción bloquea filas de documentos y series de numeración.
        if ($this->app->isLocal()) {
            DB::listen(function ($query): void {
                if ($query->time > 500) {
                    logger()->channel('single')->warning('Consulta lenta', [
                        'sql' => $query->sql,
                        'ms' => $query->time,
                    ]);
                }
            });
        }
    }
}
