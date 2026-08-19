<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correlativos de documentos. Cada serie se toma con SELECT ... FOR UPDATE
 * dentro de la transacción del documento, que es lo que garantiza numeración
 * sin huecos ni colisiones bajo concurrencia. `MAX(numero) + 1` no lo garantiza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('type', 40)->comment('journal_entry, sale_invoice, receipt...');

            // '*' para un correlativo continuo, o el año ('2026') para reiniciar
            // la numeración cada ejercicio.
            $table->string('period_key', 10)->default('*');

            $table->string('prefix', 10)->default('');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(6);

            $table->timestamps();

            // MySQL considera distintos los NULL en un índice único, así que una
            // serie sin sucursal podría duplicarse. La columna generada
            // convierte el NULL en 0 y hace que el índice sí aplique.
            //
            // Virtual y no almacenada: MySQL prohíbe ON DELETE CASCADE sobre una
            // columna base de una columna generada STORED, y branch_id la necesita.
            $table->unsignedBigInteger('branch_key')->virtualAs('COALESCE(branch_id, 0)');
            $table->unique(['company_id', 'branch_key', 'type', 'period_key'], 'document_series_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_series');
    }
};
