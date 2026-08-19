<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de la partida. Fuente de verdad de todos los libros y estados
 * financieros: `account_balances` es solo una materialización de esta tabla y
 * se puede reconstruir a partir de ella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();

            // Denormalizado para poder filtrar el mayor sin JOIN con la partida.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('line_number');
            $table->string('description', 255)->nullable();

            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);

            // Cliente, proveedor o empleado al que se imputa la línea. Polimórfico
            // sin FK porque los módulos correspondientes llegan en fases posteriores.
            $table->string('partner_type', 30)->nullable();
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->string('document_ref', 60)->nullable();

            $table->decimal('foreign_amount', 18, 4)->nullable();

            $table->unique(['journal_entry_id', 'line_number']);
            $table->index(['company_id', 'account_id']);
            $table->index(['partner_type', 'partner_id']);
        });

        // Una línea es cargo o abono, nunca ambos ni ninguno. Se garantiza en la
        // base de datos, no solo en PHP: cualquier ruta de escritura futura
        // (import, job, consola) queda cubierta.
        DB::statement(<<<'SQL'
            ALTER TABLE journal_entry_lines
            ADD CONSTRAINT journal_entry_lines_debit_xor_credit
            CHECK (
                (debit > 0 AND credit = 0)
                OR (credit > 0 AND debit = 0)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE journal_entry_lines
            ADD CONSTRAINT journal_entry_lines_no_negatives
            CHECK (debit >= 0 AND credit >= 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
    }
};
