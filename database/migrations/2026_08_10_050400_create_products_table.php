<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de productos y servicios.
 *
 * Esta migración crea solo el catálogo: las existencias y el kardex llegan en
 * migraciones posteriores junto con el costeo promedio. `track_inventory` ya
 * distingue lo que llevará control de existencias de lo que nunca lo llevará
 * —los servicios—, para no tener que reclasificar el catálogo después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('code', 40)->comment('SKU');
            $table->string('barcode', 60)->nullable();
            $table->string('name', 200);
            $table->text('description')->nullable();

            $table->enum('type', ['product', 'service'])->default('product');

            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained()->nullOnDelete();

            // Costo de referencia mientras no exista inventario. Lo sustituye
            // el costo promedio calculado por el kardex.
            $table->decimal('cost', 18, 6)->default(0);

            $table->boolean('track_inventory')->default(false);
            $table->boolean('is_active')->default(true);

            // Cuentas específicas del producto; si están vacías se usa el mapeo
            // general de la empresa.
            $table->foreignId('income_account_id')->nullable()
                ->constrained('accounts')->restrictOnDelete();
            $table->foreignId('cost_account_id')->nullable()
                ->constrained('accounts')->restrictOnDelete();
            $table->foreignId('inventory_account_id')->nullable()
                ->constrained('accounts')->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'barcode']);
            $table->index(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
