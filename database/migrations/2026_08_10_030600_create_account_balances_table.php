<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Movimiento acumulado por cuenta y período. Evita recorrer el diario completo
 * en cada reporte.
 *
 * Guarda únicamente el movimiento del período, no los saldos inicial y final:
 * un saldo final almacenado obliga a propagar en cascada hacia todos los
 * períodos siguientes cada vez que se contabiliza algo en uno anterior, y basta
 * con que una de esas cascadas falle para que el balance mienta. El saldo
 * inicial se calcula sumando los movimientos previos, que es exacto por
 * construcción.
 *
 * La fuente de verdad sigue siendo journal_entry_lines; esta tabla se puede
 * reconstruir con `php artisan accounting:rebuild-balances`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->cascadeOnDelete();

            $table->decimal('period_debit', 18, 4)->default(0);
            $table->decimal('period_credit', 18, 4)->default(0);

            $table->timestamps();

            $table->unique(['account_id', 'accounting_period_id']);
            $table->index(['company_id', 'accounting_period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_balances');
    }
};
