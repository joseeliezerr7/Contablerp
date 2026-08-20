<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Impuestos configurables.
 *
 * La tasa no se codifica en ningún servicio: Honduras usa 15% general y 18%
 * para ciertos bienes, y esas cifras cambian por ley. Cada impuesto lleva
 * además sus dos cuentas —la de débito fiscal y la de crédito fiscal— para que
 * el motor contable no tenga que adivinarlas.
 *
 * Las retenciones y exoneraciones llegan en migraciones posteriores; esta
 * tabla ya deja el lugar donde encajarán.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('code', 20);
            $table->string('name', 60);

            // Porcentaje, no fracción: 15.000000 se lee y se captura mejor
            // que 0.150000. Seis decimales cubren tasas como 1.5% o 12.75%.
            $table->decimal('rate', 9, 6);

            // Precio con impuesto incluido (ventas al público) o excluido
            // (facturación entre empresas).
            $table->boolean('is_included')->default(false);

            $table->foreignId('payable_account_id')->nullable()
                ->constrained('accounts')->restrictOnDelete()
                ->comment('Débito fiscal: impuesto cobrado en ventas');
            $table->foreignId('creditable_account_id')->nullable()
                ->constrained('accounts')->restrictOnDelete()
                ->comment('Crédito fiscal: impuesto pagado en compras');

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxes');
    }
};
