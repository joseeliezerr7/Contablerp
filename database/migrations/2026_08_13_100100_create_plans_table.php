<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planes del servicio.
 *
 * Los límites viven aquí como columnas y no como un JSON de «features»: son
 * pocos, se consultan en cada alta, y una columna se puede indexar, comparar y
 * leer en un reporte. Un JSON habría sido más flexible y menos útil.
 *
 * `NULL` en un límite significa **sin límite**, que es distinto de cero. Cero
 * sería un plan que no deja crear nada.
 *
 * Esta tabla **no** lleva `company_id`: los planes son del proveedor del
 * servicio, no de ninguna empresa cliente. Es la primera tabla del sistema
 * fuera del alcance de `CompanyScope`, y por eso no usa `BelongsToCompany`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();

            $table->string('code', 30)->unique();
            $table->string('name', 80);
            $table->text('description')->nullable();

            $table->decimal('price', 18, 4)->default(0);
            $table->char('currency_code', 3)->default('HNL');
            $table->enum('interval', ['monthly', 'yearly'])->default('monthly');

            $table->unsignedSmallInteger('trial_days')->default(30);

            // NULL = sin límite.
            $table->unsignedInteger('max_companies')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_branches')->nullable();
            $table->unsignedInteger('max_monthly_documents')->nullable();

            // Módulos que el plan habilita. Se guardan como banderas porque son
            // decisiones comerciales que se consultan en las pantallas.
            $table->boolean('has_inventory')->default(true);
            $table->boolean('has_treasury')->default(true);
            $table->boolean('has_fixed_assets')->default(true);
            $table->boolean('has_multi_company')->default(false);

            // Un plan retirado deja de ofrecerse pero las suscripciones que ya
            // lo tienen siguen funcionando.
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'is_public']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
