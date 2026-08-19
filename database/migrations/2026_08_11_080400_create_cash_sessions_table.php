<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones de caja: apertura, cierre y arqueo.
 *
 * Al abrir se anota el fondo con el que arranca el cajero. Al cerrar se cuenta
 * el efectivo y se compara con lo que la contabilidad dice que debería haber.
 * La diferencia —sobrante o faltante— se contabiliza siempre: una caja que
 * cuadra a la fuerza no es un arqueo, es un dato borrado.
 *
 * ## Lo que se espera encontrar
 *
 * Se calcula recorriendo el libro: fondo inicial más los movimientos
 * registrados en la cuenta de caja de esa sucursal mientras la sesión estuvo
 * abierta. Se usa el instante de registro de la partida y no su fecha, porque
 * una sesión dura horas y la fecha contable solo tiene día.
 *
 * De ahí sale una condición de uso: **cada caja necesita su propia cuenta
 * contable.** Si dos cajas de la misma sucursal comparten cuenta, el arqueo no
 * puede saber cuál de las dos recibió cada lempira. Es también la práctica
 * habitual, y por eso no se intentó resolver de otra forma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete()
                ->comment('Cuenta contable de esta caja');

            $table->string('number', 30)->nullable();

            $table->timestamp('opened_at');
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->decimal('opening_float', 18, 4)->default(0)->comment('Fondo con el que abre');

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('counted_amount', 18, 4)->nullable()->comment('Efectivo contado');
            $table->decimal('expected_amount', 18, 4)->nullable()->comment('Lo que dice el libro');
            $table->decimal('difference', 18, 4)->nullable()
                ->comment('Positivo: sobrante. Negativo: faltante.');

            $table->enum('status', ['open', 'closed'])->default('open');

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'opened_at']);
        });

        // Una caja no puede tener dos sesiones abiertas a la vez: el arqueo no
        // sabría a cuál cargar la diferencia. La restricción la garantiza el
        // índice, no solo el servicio.
        DB::statement(<<<'SQL'
            ALTER TABLE cash_sessions
            ADD COLUMN open_account_key BIGINT UNSIGNED
            GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN account_id END) STORED,
            ADD UNIQUE INDEX cash_sessions_open_unique (open_account_key)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};
