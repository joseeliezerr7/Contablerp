<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas por cobrar.
 *
 * El saldo es una columna generada, no un campo que se actualice a mano: un
 * saldo almacenado por separado puede quedar desfasado del importe cobrado, y
 * entonces la antigüedad de saldos miente. Aquí es imposible por construcción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('document_number', 30);
            $table->date('date');
            $table->date('due_date');

            $table->decimal('original_amount', 18, 4);
            $table->decimal('paid_amount', 18, 4)->default(0);

            $table->enum('status', ['open', 'paid', 'voided'])->default('open');

            $table->timestamps();

            $table->index(['company_id', 'customer_id', 'status']);
            $table->index(['company_id', 'due_date']);
        });

        // Saldo derivado: siempre coherente con lo cobrado.
        DB::statement(<<<'SQL'
            ALTER TABLE receivables
            ADD COLUMN balance DECIMAL(18,4)
            GENERATED ALWAYS AS (original_amount - paid_amount) STORED,
            ADD INDEX receivables_balance_index (balance)
        SQL);

        // Nunca se puede cobrar de más ni con importes negativos.
        DB::statement(<<<'SQL'
            ALTER TABLE receivables
            ADD CONSTRAINT receivables_paid_within_original
            CHECK (paid_amount >= 0 AND original_amount > 0 AND paid_amount <= original_amount)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('receivables');
    }
};
