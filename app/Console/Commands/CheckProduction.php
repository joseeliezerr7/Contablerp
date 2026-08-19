<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Identity\Data\PermissionCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Throwable;

/**
 * Revisión previa a dejar el sistema en manos de un cliente.
 *
 * Un checklist en un documento se saltea; uno que corre no. Cada comprobación
 * de aquí salió de algo que puede pasar de verdad en una instalación real, y
 * ninguna es una opinión: o el sistema está expuesto o no lo está.
 *
 * Se ejecuta contra el entorno configurado, así que en el servidor dice la
 * verdad del servidor. Devuelve código distinto de cero si algo falla, para que
 * un script de despliegue pueda pararse solo.
 */
class CheckProduction extends Command
{
    protected $signature = 'contable:check-produccion {--strict : Trata las advertencias como fallos}';

    protected $description = 'Comprueba que la instalación esté lista para producción';

    /** @var list<array{estado: string, titulo: string, detalle: string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->checkDebug();
        $this->checkKey();
        $this->checkEnvironment();
        $this->checkUrl();
        $this->checkDatabaseUser();
        $this->checkDatabaseConnection();
        $this->checkMigrations();
        $this->checkPermissionsInSync();
        $this->checkSuperAdmin();
        $this->checkBackups();
        $this->checkMail();
        $this->checkLogLevel();

        $this->render();

        $failed = $this->count('FALLA');
        $warned = $this->count('AVISO');

        if ($failed > 0) {
            $this->newLine();
            $this->error("{$failed} comprobación(es) fallaron. No entregues así.");

            return self::FAILURE;
        }

        if ($warned > 0 && $this->option('strict')) {
            $this->newLine();
            $this->error("{$warned} advertencia(s), y --strict las trata como fallos.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info($warned > 0
            ? "Sin fallos, con {$warned} advertencia(s) que conviene revisar."
            : 'Todo en orden.');

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Comprobaciones
    |--------------------------------------------------------------------------
    */

    /**
     * Con `APP_DEBUG=true`, cualquier error de 500 le enseña al visitante el
     * código, la consulta SQL y **las variables de entorno**, contraseña de la
     * base incluida. Es la primera y la más grave.
     */
    private function checkDebug(): void
    {
        $this->record(
            config('app.debug') === false,
            'APP_DEBUG apagado',
            config('app.debug')
                ? 'Está en true: un error 500 le muestra al visitante el código y las variables de entorno, con la contraseña de la base adentro.'
                : 'En false.',
        );
    }

    private function checkKey(): void
    {
        $key = (string) config('app.key');

        $this->record(
            $key !== '',
            'APP_KEY generada',
            $key === ''
                ? 'Vacía. Sin ella las sesiones y todo lo cifrado no funcionan. Corré «php artisan key:generate».'
                : 'Presente.',
        );
    }

    private function checkEnvironment(): void
    {
        $this->record(
            app()->environment('production'),
            'APP_ENV en production',
            app()->environment('production')
                ? 'En production.'
                : 'Está en «'.app()->environment().'». Laravel deja de pedir confirmación en comandos destructivos cuando no es production.',
        );
    }

    /**
     * Sin HTTPS, la contraseña del contador viaja en claro por el wifi de la
     * oficina. Con `APP_URL` en http, además, los enlaces de los correos y los
     * PDF salen apuntando a http.
     */
    private function checkUrl(): void
    {
        $url = (string) config('app.url');

        $this->record(
            str_starts_with($url, 'https://'),
            'APP_URL con HTTPS',
            str_starts_with($url, 'https://')
                ? $url
                : "Es «{$url}». Sin HTTPS las contraseñas viajan en claro por la red de la oficina.",
        );
    }

    /**
     * Si la aplicación entra como root, una inyección SQL —o un error de un
     * comando— puede borrar cualquier base del servidor, no solo la suya.
     */
    private function checkDatabaseUser(): void
    {
        $user = (string) config('database.connections.'.config('database.default').'.username');
        $password = (string) config('database.connections.'.config('database.default').'.password');

        $this->record(
            $user !== 'root' && $user !== '',
            'Usuario de base propio, no root',
            $user === 'root'
                ? 'La aplicación entra como root. Creale un usuario con permisos solo sobre su base.'
                : "Entra como «{$user}».",
        );

        $this->record(
            $password !== '',
            'Contraseña de base no vacía',
            $password === '' ? 'Está vacía.' : 'Configurada.',
        );
    }

    private function checkDatabaseConnection(): void
    {
        try {
            DB::connection()->getPdo();
            $this->record(true, 'Conexión a la base', 'Responde.');
        } catch (Throwable $e) {
            $this->record(false, 'Conexión a la base', $e->getMessage());
        }
    }

    private function checkMigrations(): void
    {
        try {
            $pending = collect(app('migrator')->getMigrationFiles(app('migrator')->paths() ?: [database_path('migrations')]))
                ->keys()
                ->diff(app('migrator')->getRepository()->getRan())
                ->count();
        } catch (Throwable $e) {
            $this->record(false, 'Migraciones al día', $e->getMessage());

            return;
        }

        $this->record(
            $pending === 0,
            'Migraciones al día',
            $pending === 0 ? 'Ninguna pendiente.' : "{$pending} pendiente(s). Corré «php artisan migrate --force».",
        );
    }

    /**
     * Los roles se siembran al crear la empresa. Un permiso nuevo en el
     * catálogo no llega solo a las empresas que ya existen, y el síntoma es un
     * 403 en una pantalla que debería abrirse.
     */
    private function checkPermissionsInSync(): void
    {
        if (! Schema::hasTable('permissions')) {
            $this->record(false, 'Permisos sembrados', 'Falta la tabla; corré las migraciones.');

            return;
        }

        $catalogo = count(PermissionCatalog::permissions());
        $enBase = Permission::query()->count();

        $this->record(
            $enBase >= $catalogo,
            'Permisos sincronizados',
            $enBase >= $catalogo
                ? "{$enBase} en la base sobre {$catalogo} del catálogo."
                : 'Faltan '.($catalogo - $enBase).' permiso(s). Corré «php artisan identity:sync-roles» — va en cada despliegue.',
        );
    }

    private function checkSuperAdmin(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $count = DB::table('users')->where('is_super_admin', true)->count();

        $this->record(
            $count > 0,
            'Superadministrador creado',
            $count > 0
                ? "{$count} cuenta(s)."
                : 'Ninguna. Nadie puede entrar al panel del proveedor para dar de alta clientes.',
            warnOnly: true,
        );
    }

    private function checkBackups(): void
    {
        $directory = (string) config('backup.directory');
        $latest = null;

        if (is_dir($directory)) {
            $files = glob($directory.'/*.sql.gz') ?: [];
            $latest = $files === [] ? null : max(array_map('filemtime', $files));
        }

        if ($latest === null) {
            $this->record(false, 'Respaldos corriendo', 'No hay ninguno. Programá «php artisan db:backup» en cron.', warnOnly: true);

            return;
        }

        $hours = (int) round((time() - $latest) / 3600);

        $this->record(
            $hours <= 48,
            'Respaldos corriendo',
            $hours <= 48
                ? "El último, hace {$hours} h."
                : "El último es de hace {$hours} h. El cron no está corriendo.",
            warnOnly: true,
        );
    }

    private function checkMail(): void
    {
        $mailer = (string) config('mail.default');

        $this->record(
            $mailer !== 'log' && $mailer !== 'array',
            'Correo configurado',
            $mailer === 'log' || $mailer === 'array'
                ? "Está en «{$mailer}»: nadie puede recuperar su contraseña olvidada."
                : "Usa «{$mailer}».",
            warnOnly: true,
        );
    }

    private function checkLogLevel(): void
    {
        $level = (string) config('logging.channels.single.level', 'debug');

        $this->record(
            $level !== 'debug',
            'Nivel de log razonable',
            $level === 'debug'
                ? 'En «debug»: el disco se llena y los datos de los clientes acaban en el archivo.'
                : "En «{$level}».",
            warnOnly: true,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Salida
    |--------------------------------------------------------------------------
    */

    private function record(bool $passed, string $title, string $detail, bool $warnOnly = false): void
    {
        $this->results[] = [
            'estado' => $passed ? 'BIEN' : ($warnOnly ? 'AVISO' : 'FALLA'),
            'titulo' => $title,
            'detalle' => $detail,
        ];
    }

    private function render(): void
    {
        $this->newLine();

        foreach ($this->results as $result) {
            $color = match ($result['estado']) {
                'BIEN' => 'green',
                'AVISO' => 'yellow',
                default => 'red',
            };

            $this->line(sprintf(
                '  <fg=%s>%-5s</> %-32s %s',
                $color,
                $result['estado'],
                $result['titulo'],
                $result['detalle'],
            ));
        }
    }

    private function count(string $estado): int
    {
        return count(array_filter($this->results, fn (array $r) => $r['estado'] === $estado));
    }
}
