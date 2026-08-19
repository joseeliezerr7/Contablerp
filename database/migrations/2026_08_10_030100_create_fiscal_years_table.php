<?php

declare(strict_types=1);

use App\Domains\Accounting\Enums\FiscalYearStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ejercicio fiscal. No siempre coincide con el año calendario: la empresa
 * define su mes de inicio en `companies.fiscal_year_start_month`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('name', 20);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->enum('status', FiscalYearStatus::values())->default(FiscalYearStatus::Open->value);

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};
