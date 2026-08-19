<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
|
| Todo esto lo dispara una sola línea de cron en el servidor:
|
|   * * * * * cd /var/www/contable && php artisan schedule:run >> /dev/null 2>&1
|
*/

// De madrugada, cuando nadie factura. `withoutOverlapping` evita que un
// respaldo lento se pise con el del día siguiente, y `runInBackground` que
// bloquee al resto de las tareas.
Schedule::command('db:backup')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->runInBackground();

// Marca vencidas las autorizaciones del SAR cuyo plazo pasó, para que nadie
// emita con un CAI muerto por no haberse dado cuenta.
Schedule::command('fiscal:expire-authorizations')
    ->dailyAt('00:15')
    ->withoutOverlapping();
