<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Activos fijos y sus categorías.
 *
 * La categoría existe porque un edificio y una computadora no van a la misma
 * cuenta contable ni se deprecian en el mismo plazo. Guarda las tres cuentas
 * —activo, gasto por depreciación y depreciación acumulada— y la vida útil por
 * defecto; el activo puede apartarse de ellas, pero casi nunca hace falta.
 *
 * ## Lo que el activo guarda y lo que deriva
 *
 * Guarda el costo, el valor residual y la vida útil, que son datos del alta.
 * **La depreciación acumulada también se guarda**, y esta vez sí es un
 * duplicado deliberado del libro: la corrida mensual necesita saber cuánto
 * lleva depreciado cada activo sin recorrer todas las partidas históricas, y
 * hay una prueba de invariante que comprueba que ambos números coinciden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('code', 20);
            $table->string('name', 120);

            $table->unsignedSmallInteger('useful_life_months')->default(60);

            $table->foreignId('asset_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('depreciation_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('accumulated_account_id')->constrained('accounts')->restrictOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('fixed_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fixed_asset_category_id')->constrained()->restrictOnDelete();

            $table->string('code', 30);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('serial_number', 80)->nullable();
            $table->string('location', 120)->nullable();

            $table->date('acquired_on');
            $table->decimal('cost', 18, 4);
            $table->decimal('salvage_value', 18, 4)->default(0)
                ->comment('Valor al que deja de depreciarse');
            $table->unsignedSmallInteger('useful_life_months');

            // Solo línea recta por ahora. La columna existe para que añadir
            // otro método no obligue a migrar los datos.
            $table->enum('method', ['straight_line'])->default('straight_line');

            $table->decimal('accumulated_depreciation', 18, 4)->default(0);
            $table->date('depreciated_through')->nullable()
                ->comment('Último mes incluido en una corrida');

            $table->enum('status', ['active', 'fully_depreciated', 'disposed'])->default('active');

            $table->date('disposed_on')->nullable();
            $table->decimal('disposal_amount', 18, 4)->nullable()->comment('Lo que se recibió por él');
            $table->text('disposal_reason')->nullable();

            // Compra que lo originó, si se dio de alta desde una factura.
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'fixed_asset_category_id']);
        });

        // El valor en libros es costo menos lo depreciado. Como columna
        // generada no puede desviarse de sus dos sumandos.
        DB::statement(<<<'SQL'
            ALTER TABLE fixed_assets
            ADD COLUMN book_value DECIMAL(18,4)
            GENERATED ALWAYS AS (cost - accumulated_depreciation) STORED
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('fixed_asset_categories');
    }
};
