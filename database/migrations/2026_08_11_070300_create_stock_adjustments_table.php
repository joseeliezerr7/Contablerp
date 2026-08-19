<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes de existencias.
 *
 * Es el único documento que puede mover el inventario sin que haya habido una
 * compra o una venta, y por eso es el que más se audita: siempre exige un
 * motivo y siempre genera partida contable. Un faltante no es un dato que se
 * corrige en una pantalla, es una pérdida que alguien tiene que explicar.
 *
 * Lleva borrador porque el uso principal es el conteo físico: se cuenta un día,
 * se revisa contra el sistema, y se contabiliza cuando las diferencias están
 * explicadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();

            $table->string('number', 30)->nullable();
            $table->date('date');

            $table->enum('reason', [
                'count',    // Diferencia de conteo físico
                'damage',   // Producto dañado
                'loss',     // Faltante o robo
                'expiry',   // Vencido
                'opening',  // Carga de existencia inicial
                'other',
            ])->default('count');

            // Cuenta de gasto o ingreso contra la que se registra la diferencia.
            // Nula: se resuelve por el mapeo `inventory.adjustment`.
            $table->foreignId('adjustment_account_id')->nullable()
                ->constrained('accounts')->restrictOnDelete();

            $table->decimal('total_value', 18, 4)->default(0)
                ->comment('Valor neto con signo: positivo si el inventario sube');

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
            $table->index(['company_id', 'warehouse_id']);
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('line_number');

            // Cantidad con signo: positiva si sobra, negativa si falta.
            $table->decimal('quantity', 18, 6);

            // Costo con el que se valoriza la diferencia. En una salida lo pone
            // el promedio vigente; en una entrada lo teclea el usuario, porque
            // no hay promedio del que sacarlo si la existencia está en cero.
            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->decimal('total_value', 18, 4)->default(0);

            $table->string('description', 200)->nullable();

            $table->timestamps();

            $table->unique(['stock_adjustment_id', 'line_number']);
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
    }
};
