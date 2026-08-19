<?php

declare(strict_types=1);

use App\Domains\Accounting\Enums\AccountNature;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\CashFlowClass;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de cuentas. Jerárquico mediante `parent_id` más un materialized path
 * en `path`, que permite consultar toda una rama con un solo LIKE en vez de
 * recorrer la jerarquía nivel por nivel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();

            $table->string('code', 20);
            $table->string('name', 150);
            $table->enum('type', AccountType::values());
            $table->enum('nature', AccountNature::values());
            $table->unsignedTinyInteger('level')->default(1);

            // Solo las cuentas de detalle (hojas) reciben movimientos. Una cuenta
            // con hijos es de agrupación y nunca puede tener partidas.
            $table->boolean('is_postable')->default(true);

            // Las cuentas del catálogo base que el motor contable necesita para
            // operar; no se pueden eliminar ni cambiarles el tipo.
            $table->boolean('is_system')->default(false);

            $table->enum('cash_flow_class', CashFlowClass::values())->nullable();
            $table->boolean('requires_partner')->default(false);
            $table->boolean('requires_branch')->default(false);
            $table->char('currency_code', 3)->nullable();
            $table->boolean('is_active')->default(true);

            // Ruta materializada: '1/1.1/1.1.01'
            $table->string('path', 255);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'parent_id']);
            $table->index(['company_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
