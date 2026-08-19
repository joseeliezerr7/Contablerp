<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compras a proveedores.
 *
 * A diferencia de las ventas, el número no lo pone el sistema: es el de la
 * factura que emitió el proveedor. Por eso `supplier_invoice_number` es
 * obligatorio y único por proveedor —registrar dos veces la misma factura de
 * compra duplicaría el gasto y el crédito fiscal—, mientras que `number` es el
 * correlativo interno del documento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();

            $table->string('number', 30)->nullable()->comment('Correlativo interno');
            $table->string('supplier_invoice_number', 40)->comment('Número de la factura del proveedor');
            $table->date('date');
            $table->date('due_date')->nullable();

            $table->enum('payment_condition', ['cash', 'credit'])->default('credit');
            $table->unsignedSmallInteger('credit_days')->default(0);

            // Cuenta de caja o banco de la que sale el dinero en una compra de
            // contado. Nula en compras al crédito.
            $table->foreignId('payment_account_id')->nullable()
                ->constrained('accounts')->restrictOnDelete();

            $table->char('currency_code', 3)->default('HNL');
            $table->decimal('exchange_rate', 18, 8)->default(1);

            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount_total', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);

            $table->enum('status', ['draft', 'received', 'voided'])->default('draft');

            $table->text('notes')->nullable();

            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'supplier_id']);
        });

        // La misma factura no puede registrarse dos veces al mismo proveedor.
        //
        // La restricción solo aplica a las compras **recibidas**: un borrador es
        // trabajo en curso y dos capturas simultáneas del mismo documento no
        // deben chocar antes de tiempo —además, si chocaran ahí, el usuario
        // vería el error crudo de la base de datos en vez del mensaje que
        // explica el problema. Las anuladas también quedan fuera, para poder
        // corregir y volver a registrar.
        DB::statement(<<<'SQL'
            ALTER TABLE purchases
            ADD COLUMN supplier_invoice_key VARCHAR(80)
            GENERATED ALWAYS AS (
                CASE WHEN status = 'received'
                THEN CONCAT(supplier_id, ':', supplier_invoice_number) END
            ) STORED,
            ADD UNIQUE INDEX purchases_supplier_invoice_unique (supplier_invoice_key)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
