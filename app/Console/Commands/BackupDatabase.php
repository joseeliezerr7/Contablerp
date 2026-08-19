<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Respaldo de la base de datos.
 *
 * Un sistema contable sin respaldo automático no se instala en un cliente. La
 * contabilidad de una empresa es su historia fiscal: se puede reconstruir una
 * factura perdida preguntándole al cliente, pero no cinco años de asientos.
 *
 * ## Por qué la contraseña no va en la línea de comandos
 *
 * `mysqldump -p<clave>` deja la contraseña visible en `ps aux` para cualquier
 * usuario de la máquina, y en el historial del shell. Se pasa por la variable
 * de entorno `MYSQL_PWD`, que solo ve el proceso hijo.
 *
 * ## Por qué se comprueba que el archivo sirva
 *
 * Un respaldo que falló en silencio es peor que no tener respaldo: da confianza
 * sin darla. El comando verifica que el volcado termine con la marca que
 * `mysqldump` escribe al final, y falla ruidosamente si no está.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup
        {--connection= : Conexión a respaldar; por defecto la predeterminada}
        {--keep= : Días de respaldos que se conservan; por defecto los de config/backup.php}
        {--path= : Carpeta destino; por defecto la de config/backup.php}';

    protected $description = 'Vuelca la base de datos a un archivo comprimido y borra los respaldos viejos';

    public function handle(): int
    {
        $connection = $this->option('connection') ?: config('database.default');
        $config = config("database.connections.{$connection}");

        if ($config === null) {
            $this->error("No existe la conexión «{$connection}».");

            return self::FAILURE;
        }

        if (($config['driver'] ?? null) !== 'mysql') {
            $this->error('Solo se respalda MySQL; «'.$connection.'» usa '.($config['driver'] ?? 'un driver desconocido').'.');

            return self::FAILURE;
        }

        $directory = $this->option('path') ?: config('backup.directory');
        File::ensureDirectoryExists($directory);

        $file = sprintf('%s/%s-%s.sql', $directory, $config['database'], now()->format('Y-m-d-His'));

        $this->line("Respaldando {$config['database']}…");

        $dump = $this->dump($config, $file);

        if (! $dump) {
            return self::FAILURE;
        }

        $compressed = $this->compress($file);

        if ($compressed === null) {
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Respaldo listo: %s (%s)',
            basename($compressed),
            $this->humanSize((int) filesize($compressed)),
        ));

        $this->prune($directory);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function dump(array $config, string $file): bool
    {
        $process = new Process([
            (string) config('backup.mysqldump'),
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            // Sin bloquear las tablas: el sistema sigue facturando mientras se
            // respalda. InnoDB lo permite con una transacción consistente.
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--default-character-set=utf8mb4',
            // Sin `--databases`: el volcado no lleva CREATE DATABASE, así se
            // puede restaurar en una base con otro nombre.
            $config['database'],
        ], timeout: 3600, env: [
            // Fuera de `ps aux` y fuera del historial.
            'MYSQL_PWD' => (string) $config['password'],
        ]);

        $handle = fopen($file, 'wb');

        if ($handle === false) {
            $this->error("No se pudo escribir en {$file}.");

            return false;
        }

        try {
            $process->run(function (string $type, string $buffer) use ($handle): void {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);

                    return;
                }

                // `mysqldump` avisa por stderr de cosas que no son errores.
                if (! str_contains($buffer, '[Warning]')) {
                    $this->line(trim($buffer));
                }
            });
        } finally {
            fclose($handle);
        }

        if (! $process->isSuccessful()) {
            $this->error('mysqldump falló con código '.$process->getExitCode().'.');
            @unlink($file);

            return false;
        }

        if (! $this->looksComplete($file)) {
            $this->error('El volcado quedó truncado: no termina con la marca de mysqldump. No se conserva.');
            @unlink($file);

            return false;
        }

        return true;
    }

    /**
     * `mysqldump` cierra el archivo con «Dump completed on …». Si esa línea no
     * está, el proceso murió a medias aunque haya devuelto cero.
     */
    private function looksComplete(string $file): bool
    {
        $size = filesize($file);

        if ($size === false || $size === 0) {
            return false;
        }

        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return false;
        }

        fseek($handle, max(0, $size - 200));
        $tail = (string) fread($handle, 200);
        fclose($handle);

        return str_contains($tail, 'Dump completed');
    }

    private function compress(string $file): ?string
    {
        $target = $file.'.gz';

        $input = fopen($file, 'rb');
        $output = gzopen($target, 'wb9');

        if ($input === false || $output === false) {
            $this->error('No se pudo comprimir el respaldo.');

            return null;
        }

        while (! feof($input)) {
            gzwrite($output, (string) fread($input, 512 * 1024));
        }

        fclose($input);
        gzclose($output);
        unlink($file);

        return $target;
    }

    /**
     * Borra los respaldos más viejos que `--keep` días.
     *
     * Nunca borra el último que queda: un servidor con el reloj mal puesto o
     * varios días sin respaldar no puede quedarse sin ninguno.
     */
    private function prune(string $directory): void
    {
        $backups = collect(File::files($directory))
            ->filter(fn ($file) => str_ends_with($file->getFilename(), '.sql.gz'))
            ->sortByDesc(fn ($file) => $file->getFilename())
            ->values();

        $keep = (int) ($this->option('keep') ?: config('backup.keep_days'));
        $limit = now()->subDays($keep)->getTimestamp();
        $removed = 0;

        foreach ($backups as $index => $file) {
            if ($index === 0) {
                continue;
            }

            if ($file->getMTime() < $limit) {
                File::delete($file->getPathname());
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->line("Se borraron {$removed} respaldo(s) de más de {$keep} días.");
        }
    }

    private function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1).' '.$unit;
            }

            $bytes = intdiv($bytes, 1024);
        }

        return $bytes.' TB';
    }
}
