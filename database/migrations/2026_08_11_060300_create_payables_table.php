<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas por pagar. Mismo diseño que las cuentas por cobrar: el saldo es una
 * columna generada, imposible de desincronizar de lo pagado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('document_number', 40);
            $table->date('date');
            $table->date('due_date');

            $table->decimal('original_amount', 18, 4);
            $table->decimal('paid_amount', 18, 4)->default(0);

            $table->enum('status', ['open', 'paid', 'voided'])->default('open');

            $table->timestamps();

            $table->index(['company_id', 'supplier_id', 'status']);
            $table->index(['company_id', 'due_date']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payables
            ADD COLUMN balance DECIMAL(18,4)
            GENERATED ALWAYS AS (original_amount - paid_amount) STORED,
            ADD INDEX payables_balance_index (balance)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payables
            ADD CONSTRAINT payables_paid_within_original
            CHECK (paid_amount >= 0 AND original_amount > 0 AND paid_amount <= original_amount)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payables');
    }
};
