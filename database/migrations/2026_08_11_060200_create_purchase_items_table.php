<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('line_number');
            $table->string('description', 255);

            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_price', 18, 6);

            $table->decimal('discount_rate', 9, 6)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);

            $table->foreignId('tax_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('tax_rate', 9, 6)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);

            $table->decimal('subtotal', 18, 4);
            $table->decimal('total', 18, 4);

            // Cuenta a la que va el costo de esta línea cuando no es inventario:
            // un mismo proveedor factura mercadería y servicios en el mismo
            // documento, y cada línea puede ir a un gasto distinto.
            $table->foreignId('expense_account_id')->nullable()
                ->constrained('accounts')->restrictOnDelete();

            $table->unique(['purchase_id', 'line_number']);
            $table->index(['company_id', 'product_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE purchase_items
            ADD CONSTRAINT purchase_items_positive_amounts
            CHECK (quantity > 0 AND unit_price >= 0 AND discount_rate >= 0 AND discount_rate <= 100)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};
