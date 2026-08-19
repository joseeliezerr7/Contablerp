<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kardex: el libro de existencias.
 *
 * Es a las existencias lo que `journal_entries` es a los saldos contables —el
 * registro inmutable del que todo lo demás se deriva—, y como aquél, no se
 * edita ni se borra: un movimiento equivocado se corrige con otro movimiento.
 *
 * Cantidad y valor van **con signo**: positivos si entran, negativos si salen.
 * Esa sola decisión hace que la invariante de la fase se pueda comprobar con
 * una suma: `SUM(total_value)` de un producto es su valor en existencia, y la
 * suma de todos los productos tiene que dar el saldo de la cuenta contable de
 * inventario. Si en su lugar se guardaran columnas separadas de entrada y
 * salida, cada comparación tendría que reconstruir el signo y sería fácil
 * equivocarse.
 *
 * Los saldos corridos (`balance_*`) se guardan calculados para que el kardex se
 * imprima sin recalcular toda la historia. Reflejan el orden en que se
 * registraron los movimientos, no la fecha de los documentos: un movimiento con
 * fecha anterior registrado después se asienta al final, como en un libro de
 * papel. El costo promedio de una salida es el que había cuando se registró.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->date('date');

            $table->enum('type', [
                'opening',        // Existencia inicial
                'purchase',       // Compra recibida
                'sale',           // Venta emitida
                'purchase_void',  // Devolución al proveedor por anulación
                'sale_void',      // Reingreso por anulación de venta
                'adjustment_in',  // Ajuste que suma (sobrante de conteo)
                'adjustment_out', // Ajuste que resta (faltante, merma, daño)
                'transfer_in',    // Entrada por traslado entre bodegas
                'transfer_out',   // Salida por traslado entre bodegas
            ]);

            // Con signo: entra positivo, sale negativo.
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_cost', 18, 6)->default(0)->comment('Costo aplicado a este movimiento');
            $table->decimal('total_value', 18, 4)->comment('Valor con signo; suma = saldo contable');

            // Saldos después de aplicar este movimiento.
            $table->decimal('balance_quantity', 18, 6);
            $table->decimal('balance_value', 18, 4);

            // Documento que lo originó, con el mismo criterio que las partidas
            // contables.
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reference', 40)->nullable()->comment('Número del documento');
            $table->string('description', 200)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // El kardex de un producto en una bodega, en orden.
            $table->index(['company_id', 'product_id', 'warehouse_id', 'id'], 'inventory_movements_kardex_index');
            $table->index(['company_id', 'date']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
