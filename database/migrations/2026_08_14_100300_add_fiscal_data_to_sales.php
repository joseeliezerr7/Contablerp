<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos fiscales congelados en la factura.
 *
 * El CAI, el rango y la fecha límite se copian en el documento al emitirlo, igual
 * que la Fase 3 congela la tasa del impuesto. La razón es la misma y aquí pesa
 * más: reimprimir una factura de hace dos años tiene que producir **el mismo
 * papel** que se entregó entonces. Si los datos se leyeran de la autorización
 * vigente, la reimpresión mostraría un CAI que esa factura nunca llevó, y eso es
 * un documento falso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignId('fiscal_authorization_id')->nullable()->after('branch_id')
                ->constrained('fiscal_authorizations')->restrictOnDelete();

            $table->string('cai', 40)->nullable()->after('number');
            $table->unsignedBigInteger('fiscal_range_from')->nullable()->after('cai');
            $table->unsignedBigInteger('fiscal_range_to')->nullable()->after('fiscal_range_from');
            $table->date('fiscal_limit_date')->nullable()->after('fiscal_range_to');

            // El correlativo desnudo, sin el prefijo del punto de emisión. Es lo
            // que permite comprobar de un vistazo que la numeración no tiene
            // huecos, sin tener que descomponer la cadena en SQL.
            $table->unsignedBigInteger('fiscal_sequence')->nullable()->after('fiscal_limit_date');

            $table->index(['company_id', 'fiscal_authorization_id', 'fiscal_sequence'], 'sales_fiscal_sequence_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex('sales_fiscal_sequence_index');
            $table->dropConstrainedForeignId('fiscal_authorization_id');
            $table->dropColumn([
                'cai', 'fiscal_range_from', 'fiscal_range_to',
                'fiscal_limit_date', 'fiscal_sequence',
            ]);
        });
    }
};
