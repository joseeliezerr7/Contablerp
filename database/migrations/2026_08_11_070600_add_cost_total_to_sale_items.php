<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costo total de la línea de venta.
 *
 * `unit_cost` ya existía, pero multiplicarlo por la cantidad para obtener el
 * costo de ventas puede dar un centavo distinto al que el kardex descargó: el
 * costo unitario es un cociente redondeado, y redondear y luego multiplicar no
 * es lo mismo que multiplicar y luego redondear.
 *
 * Ese centavo importa porque las dos cifras van a sitios distintos —una a la
 * cuenta de inventario por la vía del kardex, otra por la vía de la partida— y
 * tienen que ser el mismo número. Así que se guarda el importe exacto que salió
 * del kardex, y `unit_cost` queda como dato informativo del kardex impreso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->decimal('cost_total', 18, 4)->default(0)->after('unit_cost')
                ->comment('Importe exacto descargado del kardex');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn('cost_total');
        });
    }
};
