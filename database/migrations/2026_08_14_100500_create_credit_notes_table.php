<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notas de crédito.
 *
 * Tabla propia y no una factura con importe negativo. Una nota de crédito lleva
 * su **propia** autorización del SAR, con su CAI y su correlativo, así que
 * mezclarla con las facturas obligaría a que una misma serie avanzara con dos
 * numeraciones fiscales distintas. Además su ciclo es otro: no se cobra, se
 * aplica; y no se puede emitir sin una factura detrás.
 *
 * Los datos fiscales se congelan igual que en la factura, y por la misma razón:
 * una reimpresión tiene que dar el mismo papel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            // La factura que se acredita. Restrict y no cascade: borrar una
            // factura que ya tiene nota de crédito dejaría el documento fiscal
            // huérfano, y un documento fiscal no se borra.
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();

            $table->foreignId('fiscal_authorization_id')->nullable()
                ->constrained('fiscal_authorizations')->restrictOnDelete();

            $table->string('number', 30)->nullable()->comment('Nulo mientras es borrador');
            $table->string('cai', 40)->nullable();
            $table->unsignedBigInteger('fiscal_range_from')->nullable();
            $table->unsignedBigInteger('fiscal_range_to')->nullable();
            $table->date('fiscal_limit_date')->nullable();
            $table->unsignedBigInteger('fiscal_sequence')->nullable();

            $table->date('date');
            $table->string('reason', 20)->comment('return, discount, correction');
            $table->text('description');

            // Si la mercadería vuelve a la bodega. Una nota por descuento o por
            // corrección de precio no mueve existencias.
            $table->boolean('restocks')->default(false);
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();

            $table->char('currency_code', 3)->default('HNL');
            $table->decimal('exchange_rate', 18, 8)->default(1);

            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount_total', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);

            $table->string('status', 20)->default('draft')->comment('draft, issued, voided');

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
            $table->index(['company_id', 'sale_id']);
        });

        Schema::create('credit_note_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_note_id')->constrained()->cascadeOnDelete();

            // La línea de la factura que se acredita, para poder comprobar que
            // no se acredita más cantidad de la que se vendió.
            $table->foreignId('sale_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('line_number');
            $table->string('description', 255);

            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount_rate', 9, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);

            $table->foreignId('tax_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('tax_rate', 9, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);

            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);

            // Costo con el que la mercadería vuelve al inventario: el mismo con
            // el que salió, no el promedio de hoy.
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('cost_total', 18, 4)->default(0);

            $table->timestamps();

            $table->index(['company_id', 'credit_note_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_items');
        Schema::dropIfExists('credit_notes');
    }
};
