<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recibos de cobro. Un recibo puede aplicarse a varias facturas del mismo
 * cliente, que es como cobra un vendedor en ruta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            $table->string('number', 30);
            $table->date('date');

            $table->enum('payment_method', ['cash', 'check', 'transfer', 'card', 'other'])
                ->default('cash');
            $table->string('reference', 100)->nullable()
                ->comment('Número de cheque, transferencia o autorización');

            // Cuenta de caja o banco donde entra el dinero.
            $table->foreignId('deposit_account_id')->constrained('accounts')->restrictOnDelete();

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
            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
