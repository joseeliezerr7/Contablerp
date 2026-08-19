<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca qué cuentas son efectivo y equivalentes.
 *
 * El estado de flujo de efectivo por método directo necesita saber qué
 * movimientos son de caja. Deducirlo del código de cuenta ('1.1.01...') ataría
 * el reporte al catálogo hondureño; una bandera explícita funciona con
 * cualquier plan de cuentas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->boolean('is_cash_equivalent')->default(false)->after('cash_flow_class');

            $table->index(['company_id', 'is_cash_equivalent']);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'is_cash_equivalent']);
            $table->dropColumn('is_cash_equivalent');
        });
    }
};
