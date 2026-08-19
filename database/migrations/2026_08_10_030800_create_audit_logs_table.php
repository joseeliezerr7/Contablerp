<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de auditoría.
 *
 * Sin `updated_at`: un registro de auditoría que se puede modificar no sirve
 * como evidencia. Se escribe una vez y no se toca.
 *
 * `company_id` y `user_id` quedan en NULL si la empresa o el usuario se
 * eliminan, para que el rastro sobreviva a la baja de quien lo generó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('event', 30)->comment('created, updated, deleted, posted, voided, reversed, closed, reopened');
            $table->string('auditable_type', 255);
            $table->unsignedBigInteger('auditable_id');
            $table->string('module', 40)->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['company_id', 'event']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
