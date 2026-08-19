<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('code', 20);
            $table->string('name', 200)->comment('Razón social o nombre completo');
            $table->string('trade_name', 200)->nullable();
            $table->string('tax_id', 20)->nullable()->comment('RTN');
            $table->enum('type', ['individual', 'company'])->default('company');

            $table->string('email', 150)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();

            $table->foreignId('price_list_id')->nullable()->constrained()->nullOnDelete();

            // Cero significa sin crédito: solo ventas de contado.
            $table->decimal('credit_limit', 18, 4)->default(0);
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
        Schema::dropIfExists('customers');
    }
};
