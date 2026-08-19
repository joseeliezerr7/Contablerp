<?php

declare(strict_types=1);

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Enums\JournalEntryType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partida contable. Es el único registro que afecta los libros; ventas,
 * compras, bancos, caja y activos fijos llegan aquí a través del motor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();

            // Nulo mientras la partida es borrador: numerar un borrador consumiría
            // correlativos que quizá nunca se contabilicen y dejaría huecos en el
            // libro. El folio se asigna al contabilizar.
            $table->string('number', 20)->nullable();
            $table->date('date');
            $table->enum('type', JournalEntryType::values())->default(JournalEntryType::Standard->value);
            $table->string('concept', 255);
            $table->string('reference', 100)->nullable();

            // Documento de origen: 'sale', 'purchase', 'payment', 'depreciation'...
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->char('currency_code', 3)->default('HNL');
            $table->decimal('exchange_rate', 18, 8)->default(1);

            $table->decimal('total_debit', 18, 4)->default(0);
            $table->decimal('total_credit', 18, 4)->default(0);

            $table->enum('status', JournalEntryStatus::values())->default(JournalEntryStatus::Draft->value);

            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();

            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        // Idempotencia: un documento no puede tener dos partidas vigentes.
        // MySQL no soporta índices únicos parciales, así que se usa una columna
        // generada que vale NULL cuando la partida está anulada o no proviene de
        // un documento — y los NULL sí se repiten en un índice único.
        //
        // No incluye company_id: el id de un documento ya es único globalmente
        // (una venta pertenece a una sola empresa), y company_id tiene una FK en
        // cascada, que MySQL prohíbe como columna base de una generada STORED.
        DB::statement(<<<'SQL'
            ALTER TABLE journal_entries
            ADD COLUMN source_key VARCHAR(120)
            GENERATED ALWAYS AS (
                CASE
                    WHEN status <> 'voided' AND source_type IS NOT NULL AND source_id IS NOT NULL
                    THEN CONCAT(source_type, ':', source_id)
                END
            ) STORED,
            ADD UNIQUE INDEX journal_entries_source_key_unique (source_key)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
