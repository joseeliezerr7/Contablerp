<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cheques girados por la empresa.
 *
 * Existen como tabla propia y no como un campo del pago porque un cheque tiene
 * vida después del pago: se emite hoy, se entrega mañana y el banco lo cobra la
 * semana que viene. Esa fecha de cobro es la que decide si el cheque es un
 * «cheque pendiente» en la conciliación, y no hay dónde anotarla si el cheque
 * es solo una referencia escrita en el pago.
 *
 * El importe se duplica aquí a propósito. La partida contable es la verdad del
 * saldo; este importe es el del documento en papel, y que ambos coincidan es
 * precisamente lo que la conciliación comprueba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();

            $table->string('number', 30);
            $table->date('date');
            $table->string('payee', 200)->comment('A nombre de quién se giró');
            $table->decimal('amount', 18, 4);

            $table->enum('status', [
                'issued',    // Girado, todavía en la empresa
                'delivered', // Entregado al beneficiario
                'cleared',   // Cobrado en el banco
                'voided',    // Anulado
            ])->default('issued');

            $table->date('delivered_on')->nullable();
            $table->date('cleared_on')->nullable()->comment('Fecha en que el banco lo pagó');

            // Documento que lo originó: normalmente un pago a proveedor.
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->text('notes')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // El mismo número no se gira dos veces contra la misma cuenta.
            $table->unique(['bank_account_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'date']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checks');
    }
};
