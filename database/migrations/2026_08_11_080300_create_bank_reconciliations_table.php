<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conciliación bancaria.
 *
 * Lo que se concilia son **líneas del libro diario**, no documentos. Todo lo
 * que toca el banco aterriza como línea en su cuenta contable: un recibo de
 * cobro, un pago a proveedor, una comisión registrada a mano. Conciliar
 * documentos dejaría fuera las partidas manuales, que en la práctica son la
 * mitad de lo que aparece en un extracto —comisiones, intereses, notas de
 * débito—.
 *
 * El marcado vive en una tabla aparte y no como una bandera en la línea. Una
 * bandera diría «esto está conciliado» pero no en cuál de las conciliaciones,
 * y desconciliar no dejaría rastro; además `journal_entry_lines` es inmutable
 * por diseño.
 *
 * La identidad que debe cumplirse al cerrar:
 *
 *     saldo del extracto
 *     + depósitos en tránsito   (cargos en libros que el banco aún no acredita)
 *     − cheques pendientes      (abonos en libros que el banco aún no cobra)
 *     = saldo en libros a la fecha de corte
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();

            $table->date('cutoff_date')->comment('Fecha de corte del extracto');
            $table->decimal('statement_balance', 18, 4)->comment('Saldo según el extracto');

            // Saldos calculados y congelados al cerrar, para que la
            // conciliación de marzo siga diciendo lo que decía en marzo aunque
            // después se registren partidas con fecha anterior.
            $table->decimal('book_balance', 18, 4)->default(0);
            $table->decimal('deposits_in_transit', 18, 4)->default(0);
            $table->decimal('outstanding_checks', 18, 4)->default(0);
            $table->decimal('difference', 18, 4)->default(0);

            $table->enum('status', ['draft', 'closed'])->default('draft');

            $table->text('notes')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'bank_account_id', 'cutoff_date'], 'bank_reconciliations_scope_index');
            $table->index(['company_id', 'status']);
        });

        Schema::create('bank_reconciliation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_reconciliation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_entry_line_id')->constrained()->cascadeOnDelete();

            // Fecha en que el banco lo movió, que casi nunca es la del libro.
            $table->date('cleared_on')->nullable();

            $table->timestamps();

            // Una línea del libro solo puede conciliarse una vez: si apareciera
            // en dos conciliaciones, el saldo en libros se contaría dos veces.
            $table->unique('journal_entry_line_id');
            $table->index(['company_id', 'bank_reconciliation_id'], 'bank_reconciliation_lines_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_lines');
        Schema::dropIfExists('bank_reconciliations');
    }
};
