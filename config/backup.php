<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Ruta de mysqldump
    |--------------------------------------------------------------------------
    |
    | En un servidor Linux está en el PATH y con «mysqldump» basta. En Windows
    | el instalador de MySQL no lo agrega, así que hay que darle la ruta
    | completa —por ejemplo
    | C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqldump.exe—.
    |
    */

    'mysqldump' => env('MYSQLDUMP_PATH', 'mysqldump'),

    /*
    |--------------------------------------------------------------------------
    | Dónde y por cuánto tiempo
    |--------------------------------------------------------------------------
    |
    | Por defecto los respaldos quedan dentro del proyecto, que sirve para
    | empezar pero no es un respaldo de verdad: si se pierde el disco se pierden
    | los dos. En producción esto apunta a otro volumen, y de ahí algo se los
    | lleva fuera del servidor.
    |
    */

    'directory' => env('BACKUP_PATH', storage_path('app/backups')),

    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),

];
