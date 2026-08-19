<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturas de venta.
 *
 * El vínculo con la contabilidad no se guarda aquí: `journal_entries` lleva
 * `source_type` y `source_id`, con un índice único que impide que un mismo
 * documento genere dos partidas vigentes. Duplicar la referencia en ambos
 * lados abriría la puerta a que se contradigan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            $table->string('number', 30)->nullable()->comment('Nulo mientras es borrador');
            $table->date('date');
            $table->date('due_date')->nullable();

            $table->enum('payment_condition', ['cash', 'credit'])->default('cash');
            $table->unsignedSmallInteger('credit_days')->default(0);

            // Cuenta de caja o banco donde entra el dinero en una venta de
            // contado. Nula en ventas al crédito.
            $table->foreignId('deposit_account_id')->nullable()
                ->constrained('accounts')->restrictOnDelete();

            $table->char('currency_code', 3)->default('HNL');
            $table->decimal('exchange_rate', 18, 8)->default(1);

            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount_total', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);

            $table->enum('status', ['draft', 'issued', 'voided'])->default('draft');

            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
