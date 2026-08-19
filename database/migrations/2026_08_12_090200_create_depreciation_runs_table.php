<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corridas de depreciación.
 *
 * La depreciación es un **documento mensual**, no un número que se calcula al
 * vuelo cuando alguien abre un reporte. Tiene que serlo: produce una partida
 * contable, y una partida no puede depender de cuándo se mire la pantalla.
 *
 * Una corrida por mes y empresa, garantizado por índice único. Sin esa
 * restricción, ejecutar dos veces el mismo mes duplicaría el gasto y la
 * depreciación acumulada, y el error solo se vería al comparar contra el libro
 * meses después.
 *
 * Las líneas guardan lo que le tocó a cada activo y su acumulado después de la
 * corrida, para poder auditar un mes concreto sin recalcular la historia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depreciation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('number', 30)->nullable();

            // El mes que se está depreciando, normalizado al día 1.
            $table->date('period_month');

            // Fecha con la que se asienta la partida: el último día del mes.
            $table->date('posted_on');

            $table->decimal('total', 18, 4)->default(0);
            $table->unsignedInteger('asset_count')->default(0);

            $table->enum('status', ['posted', 'voided'])->default('posted');

            $table->text('notes')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('depreciation_run_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('depreciation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 18, 4);
            $table->decimal('accumulated_after', 18, 4);
            $table->decimal('book_value_after', 18, 4);

            $table->timestamps();

            $table->unique(['depreciation_run_id', 'fixed_asset_id']);
            $table->index(['company_id', 'fixed_asset_id']);
        });

        // Un mes no se deprecia dos veces. La corrida anulada sí libera el mes:
        // se anula justamente para volver a correrlo.
        //
        // `company_id` se queda fuera de la columna generada y entra en el
        // índice: MySQL no admite una clave foránea en cascada como base de una
        // columna generada STORED, cosa que este proyecto ya aprendió a la mala
        // en la Fase 1.
        DB::statement(<<<'SQL'
            ALTER TABLE depreciation_runs
            ADD COLUMN active_period_key DATE
            GENERATED ALWAYS AS (
                CASE WHEN status = 'posted' THEN period_month END
            ) STORED,
            ADD UNIQUE INDEX depreciation_runs_period_unique (company_id, active_period_key)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_run_lines');
        Schema::dropIfExists('depreciation_runs');
    }
};
