<?php

declare(strict_types=1);

use App\Domains\Accounting\Enums\PeriodStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Período contable (normalmente un mes). Es la unidad de cierre: una partida
 * solo puede contabilizarse dentro de un período abierto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('number');
            $table->string('name', 30);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->enum('status', PeriodStatus::values())->default(PeriodStatus::Open->value);

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['fiscal_year_id', 'number']);
            // Búsqueda del período que contiene una fecha determinada.
            $table->index(['company_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
