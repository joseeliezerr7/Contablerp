<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La empresa es la unidad de aislamiento contable: dueña de su plan de
 * cuentas, sus libros y todos sus documentos. `company_id` es la llave de
 * particionamiento lógico de todo el sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('legal_name', 200);
            $table->string('trade_name', 200)->nullable();
            $table->string('tax_id', 20)->comment('RTN en Honduras');
            $table->string('address', 255)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('logo_path')->nullable();

            $table->char('country_code', 2)->default('HN');
            $table->char('currency_code', 3)->default('HNL');
            $table->string('locale', 5)->default('es');

            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->unsignedTinyInteger('decimal_places')->default(2);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'tax_id']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
