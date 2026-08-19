<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traslados de mercadería entre bodegas.
 *
 * No generan partida contable: la mercadería cambia de estante, no de dueño, y
 * el saldo de la cuenta de inventario es el mismo antes y después. Lo que sí
 * generan son dos movimientos de kardex —una salida y una entrada por el mismo
 * valor—, de modo que el costo promedio viaja con el producto y la bodega que
 * recibe no lo revaloriza.
 *
 * El traslado es inmediato: sale y entra en el mismo acto. La mercadería en
 * tránsito, que necesitaría una tercera bodega virtual y un documento de
 * recepción aparte, queda fuera de esta fase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();

            $table->string('number', 30)->nullable();
            $table->date('date');

            $table->decimal('total_value', 18, 4)->default(0);

            $table->enum('status', ['draft', 'posted', 'voided'])->default('draft');

            $table->text('notes')->nullable();

            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('line_number');

            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_cost', 18, 6)->default(0)->comment('Promedio de la bodega que envía');
            $table->decimal('total_value', 18, 4)->default(0);

            $table->timestamps();

            $table->unique(['stock_transfer_id', 'line_number']);
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
