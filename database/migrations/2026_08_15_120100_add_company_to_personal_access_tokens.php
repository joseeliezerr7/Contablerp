<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El token de API pertenece a una empresa, no solo a un usuario.
 *
 * ## Por qué
 *
 * En la aplicación web la empresa activa sale de la sesión: el usuario la elige
 * en el selector de arriba. Una petición de API no tiene sesión ni selector, y
 * un usuario puede pertenecer a varias empresas. Sin esta columna habría que
 * adivinar sobre cuál actuar —la primera, la predeterminada— y ese es
 * exactamente el tipo de suposición que termina escribiendo una factura en la
 * empresa equivocada.
 *
 * Con la empresa en el token, cada credencial dice sobre qué actúa. Un
 * integrador que lleva dos empresas pide dos tokens, que además es lo que uno
 * quiere: si le roban uno, no se lleva las dos.
 *
 * ## Qué más se guarda
 *
 * `last_used_ip` y `revoked_at` no los trae Sanctum. El primero es lo que
 * permite responder «¿desde dónde se está usando esto?» cuando alguien
 * sospecha; el segundo distingue un token que se dio de baja de uno que nunca
 * existió, que importa al leer la bitácora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('tokenable_id')
                ->constrained()->cascadeOnDelete();

            $table->string('last_used_ip', 45)->nullable()->after('last_used_at');
            $table->timestamp('revoked_at')->nullable()->after('expires_at');

            $table->index(['company_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'revoked_at']);
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['last_used_ip', 'revoked_at']);
        });
    }
};
