<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Punto de emisión del régimen de facturación hondureño.
 *
 * El número fiscal de un documento tiene cuatro partes: `EEE-PPP-TT-NNNNNNNN`
 * —establecimiento, punto de emisión, tipo de documento y correlativo—. Las dos
 * primeras las asigna el SAR al inscribir el establecimiento y no las inventa el
 * sistema: se capturan tal como vienen en la resolución.
 *
 * Un establecimiento puede tener varias cajas, y cada caja es un punto de
 * emisión con su propia numeración. Por eso el punto cuelga de la sucursal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();

            // Se guardan como texto y no como número: los ceros a la izquierda
            // son parte del código, y '000' no es 0.
            $table->char('establishment_code', 3);
            $table->char('emission_point_code', 3);

            $table->string('name', 120);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(
                ['company_id', 'establishment_code', 'emission_point_code'],
                'fiscal_points_code_unique'
            );
            $table->index(['company_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_points');
    }
};
