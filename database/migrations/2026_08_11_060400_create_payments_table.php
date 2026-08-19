<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pagos a proveedores. Espejo de los recibos de cobro: un pago puede cancelar
 * varias facturas del mismo proveedor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();

            $table->string('number', 30);
            $table->date('date');

            $table->enum('payment_method', ['cash', 'check', 'transfer', 'card', 'other'])
                ->default('transfer');
            $table->string('reference', 100)->nullable()
                ->comment('Número de cheque o transferencia');

            // Cuenta de la que sale el dinero.
            $table->foreignId('payment_account_id')->constrained('accounts')->restrictOnDelete();

            $table->decimal('amount', 18, 4);

            $table->enum('status', ['issued', 'voided'])->default('issued');

            $table->text('notes')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
