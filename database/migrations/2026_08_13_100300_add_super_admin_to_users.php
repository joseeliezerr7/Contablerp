<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Superadministrador del servicio.
 *
 * Es una bandera y no un rol porque **no es el mismo eje de autorización**. Los
 * roles del sistema son por empresa —spatie con `teams = company_id`—, y todo
 * el modelo de permisos existente responde a la pregunta «qué puede hacer este
 * usuario dentro de esta empresa». El superadministrador opera *entre* tenants,
 * donde esa pregunta no tiene sentido: no pertenece a ninguna empresa.
 *
 * De ahí dos consecuencias que el código respeta:
 *
 * - Sus pantallas viven **fuera** del middleware que exige empresa activa. Un
 *   superadmin sin empresa no debe acabar en la pantalla de «no tienes empresa
 *   asignada».
 * - Sus consultas cruzan tenants, y por tanto tienen que saltarse el filtro de
 *   empresa **de forma explícita**. Nunca por accidente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_super_admin')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_super_admin');
        });
    }
};
