<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Existencias por producto y bodega.
 *
 * Es la materialización del kardex: se puede reconstruir entera sumando
 * `inventory_movements`, y existe solo para no recorrer el libro completo cada
 * vez que alguien factura.
 *
 * El par autoritativo es **(cantidad, valor)**, no (cantidad, costo unitario).
 * El costo promedio se deriva de esos dos y por eso es una columna generada:
 * si se guardara a mano, cada redondeo del promedio dejaría un resto que no
 * está en ninguna cuenta contable, y con el tiempo el kardex valorizado
 * dejaría de coincidir con el saldo de la cuenta de inventario. Guardando el
 * valor, lo que se asienta en contabilidad y lo que se guarda aquí son
 * exactamente el mismo número.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Las cantidades llevan 6 decimales porque hay unidades que se
            // fraccionan —kilos, metros, litros—; el valor lleva 4, la misma
            // escala que toda la contabilidad.
            $table->decimal('quantity', 18, 6)->default(0);
            $table->decimal('total_value', 18, 4)->default(0);

            // Puntos de reorden, para el reporte de existencias bajo mínimo.
            $table->decimal('min_quantity', 18, 6)->default(0);
            $table->decimal('max_quantity', 18, 6)->nullable();

            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id']);
            $table->index(['company_id', 'product_id']);
        });

        // Costo promedio móvil, derivado y no almacenable a mano.
        DB::statement(<<<'SQL'
            ALTER TABLE inventory_stocks
            ADD COLUMN average_cost DECIMAL(18,6)
            GENERATED ALWAYS AS (
                CASE WHEN quantity <> 0 THEN total_value / quantity ELSE 0 END
            ) STORED
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stocks');
    }
};
