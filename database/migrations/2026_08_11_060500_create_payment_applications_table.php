<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payable_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 18, 4);

            $table->timestamps();

            $table->unique(['payment_id', 'payable_id']);
            $table->index(['company_id', 'payable_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_applications
            ADD CONSTRAINT payment_applications_positive_amount
            CHECK (amount > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_applications');
    }
};
