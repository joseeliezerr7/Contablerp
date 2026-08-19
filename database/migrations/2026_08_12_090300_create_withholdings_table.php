<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retenciones.
 *
 * ## Dónde se retiene
 *
 * **Al pagar, no al facturar.** En Honduras la retención se practica en la
 * fuente, en el momento del pago: se le debe al proveedor el total de la
 * factura, pero al pagarle sale del banco menos porque una parte se retiene
 * para el fisco. Aplicarla al registrar la factura dejaría la cuenta por pagar
 * mostrando menos de lo que dice el documento del proveedor, y el proveedor
 * reclamaría con razón.
 *
 * Lo mismo al revés: un cliente nos retiene al pagarnos, y el recibo ingresa
 * menos efectivo del que cancela de la cuenta por cobrar. La diferencia es un
 * impuesto pagado por anticipado, no un descuento.
 *
 * ## El tipo y la aplicación
 *
 * `withholding_types` es el catálogo configurable —ISR 12.5 % sobre servicios
 * profesionales, ISR 1 %, ISV retenido—, porque las tasas hondureñas cambian y
 * cambiarlas no debe requerir una migración.
 *
 * `withholdings` es cada retención practicada, colgada del documento que la
 * practicó. Guarda la tasa aplicada, no solo la referencia al tipo: si mañana
 * la tasa cambia, los documentos de ayer deben seguir mostrando la de ayer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withholding_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('code', 20);
            $table->string('name', 120);

            $table->enum('kind', ['income_tax', 'sales_tax'])->default('income_tax');

            // Sobre qué se aplica la tasa: el subtotal sin impuesto o el total.
            $table->enum('base', ['subtotal', 'total'])->default('subtotal');
            $table->decimal('rate', 9, 6)->comment('Porcentaje, por ejemplo 12.5');

            // A quién se le practica: al proveedor cuando pagamos, o a nosotros
            // cuando el cliente cobra.
            $table->enum('applies_to', ['purchase', 'sale'])->default('purchase');

            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete()
                ->comment('Retenciones por pagar, o retenciones a favor');

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'applies_to', 'is_active'], 'withholding_types_scope_index');
        });

        Schema::create('withholdings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('withholding_type_id')->constrained()->restrictOnDelete();

            // Documento que la practicó: un pago o un recibo.
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');

            $table->date('date');
            $table->decimal('base_amount', 18, 4);
            $table->decimal('rate', 9, 6)->comment('La tasa vigente cuando se practicó');
            $table->decimal('amount', 18, 4);

            $table->string('certificate_number', 40)->nullable()
                ->comment('Constancia de retención');

            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['company_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withholdings');
        Schema::dropIfExists('withholding_types');
    }
};
