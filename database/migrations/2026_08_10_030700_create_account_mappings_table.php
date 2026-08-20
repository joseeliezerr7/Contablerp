<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Puente entre los módulos operativos y el plan de cuentas.
 *
 * Sin esta tabla, las cuentas quedarían escritas dentro de los servicios y el
 * sistema solo serviría para una empresa. Cada clave ('sales.revenue',
 * 'purchases.payable'...) se resuelve aquí a la cuenta concreta de la empresa.
 *
 * La resolución en cascada por producto, categoría, cliente y sucursal se
 * añade después; hoy la cuenta es la predeterminada de la empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('key', 60);
            $table->foreignId('account_id')->constrained()->restrictOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_mappings');
    }
};
