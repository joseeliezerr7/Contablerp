<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de la factura.
 *
 * `product_id` es nulable a propósito: una factura debe poder llevar un
 * concepto libre («Servicio de instalación») sin obligar a inventar un producto
 * en el catálogo. La descripción se copia en la línea, de modo que renombrar el
 * producto después no altera lo que decía una factura ya emitida.
 *
 * La tasa del impuesto también se copia: si mañana el ISV pasa de 15% a 16%,
 * las facturas viejas deben seguir mostrando el 15% con el que se emitieron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('line_number');
            $table->string('description', 255);

            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_price', 18, 6);

            $table->decimal('discount_rate', 9, 6)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);

            $table->foreignId('tax_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('tax_rate', 9, 6)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);

            $table->decimal('subtotal', 18, 4)->comment('Cantidad × precio, ya descontado');
            $table->decimal('total', 18, 4)->comment('Subtotal + impuesto');

            // Costo unitario al momento de la venta. Se llena en la Fase 5,
            // cuando exista el kardex; hasta entonces queda en cero.
            $table->decimal('unit_cost', 18, 6)->default(0);

            $table->unique(['sale_id', 'line_number']);
            $table->index(['company_id', 'product_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE sale_items
            ADD CONSTRAINT sale_items_positive_amounts
            CHECK (quantity > 0 AND unit_price >= 0 AND discount_rate >= 0 AND discount_rate <= 100)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
