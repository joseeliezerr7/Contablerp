<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas bancarias.
 *
 * No guardan saldo. Una cuenta bancaria es **metadatos sobre una cuenta
 * contable**: el número de cuenta, el banco, el tipo y el correlativo de
 * cheques. El dinero vive donde siempre ha vivido, en el libro, y el saldo se
 * lee de ahí. Guardar aquí un saldo propio crearía un segundo número que
 * mantener sincronizado, que es justamente el error que ya costó aprender
 * con el kardex.
 *
 * La relación con `accounts` es uno a uno: cada cuenta bancaria tiene su
 * cuenta contable y ninguna cuenta contable sirve a dos bancos, porque si
 * sirviera no habría forma de conciliar por separado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            $table->string('bank_name', 120);
            $table->string('number', 40)->comment('Número de cuenta en el banco');
            $table->string('alias', 80)->nullable()->comment('Cómo la llaman en la empresa');

            $table->enum('type', ['checking', 'savings'])->default('checking');
            $table->char('currency_code', 3)->default('HNL');

            // Correlativo de la chequera en uso. Nulo si la cuenta no gira
            // cheques.
            $table->unsignedBigInteger('next_check_number')->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();

            // Una cuenta contable no puede pertenecer a dos cuentas bancarias.
            $table->unique('account_id');
            $table->unique(['company_id', 'bank_name', 'number']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
