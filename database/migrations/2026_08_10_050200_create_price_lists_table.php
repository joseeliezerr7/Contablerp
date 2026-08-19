<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listas de precios.
 *
 * Tabla y no columnas fijas en `products` («precio» y «precio mayorista»):
 * agregar un cuarto nivel de precio debe ser un dato nuevo, no una migración
 * que obligue a tocar todas las pantallas. Se siembran tres —Detalle,
 * Mayorista y Distribuidor— que es la segmentación habitual en distribución.
 *
 * Sin motor de reglas ni fórmulas: un precio plano por producto y lista. Si más
 * adelante hacen falta descuentos por volumen, se añaden sin rehacer el modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('code', 20);
            $table->string('name', 60);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_lists');
    }
};
