<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proveedores. Tabla propia y no una compartida con clientes: aunque los campos
 * se parezcan, las reglas de negocio divergen enseguida —retenciones, tipo de
 * contribuyente, cuentas contables— y una tabla común obligaría a llenarla de
 * columnas que solo aplican a la mitad de los registros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('code', 20);
            $table->string('name', 200);
            $table->string('trade_name', 200)->nullable();
            $table->string('tax_id', 20)->nullable()->comment('RTN');
            $table->enum('type', ['individual', 'company'])->default('company');

            $table->string('email', 150)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('contact_name', 150)->nullable();

            // Días de crédito que concede el proveedor.
            $table->unsignedSmallInteger('credit_days')->default(0);

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'tax_id']);
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
