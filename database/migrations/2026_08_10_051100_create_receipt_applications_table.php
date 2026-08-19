<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aplicación de un recibo a las facturas que cancela.
 *
 * Es la tabla que permite reconstruir, factura por factura, de dónde salió cada
 * lempira cobrado. Sin ella, `receivables.paid_amount` sería un número sin
 * respaldo y anular un recibo no podría saber qué saldos devolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('receivable_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 18, 4);

            $table->timestamps();

            $table->unique(['receipt_id', 'receivable_id']);
            $table->index(['company_id', 'receivable_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE receipt_applications
            ADD CONSTRAINT receipt_applications_positive_amount
            CHECK (amount > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_applications');
    }
};
