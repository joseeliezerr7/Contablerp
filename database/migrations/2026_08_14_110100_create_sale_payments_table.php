<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobros aplicados en el momento de emitir una factura de contado.
 *
 * ## Por qué una tabla y no la columna que ya había
 *
 * `sales.deposit_account_id` alcanza para una venta cobrada de una sola forma.
 * En un mostrador no: el cliente
 * paga trescientos en efectivo y el resto con tarjeta, y esos dos lempiras
 * entran en cuentas contables distintas. Con una sola columna habría que elegir
 * una y mentir sobre la otra —y la conciliación bancaria dejaría de casar—.
 *
 * La columna vieja no se elimina: las facturas emitidas antes de esta migración
 * la tienen, y las ventas al crédito que se cobran después siguen pasando por
 * recibos, que es su sitio. `SaleService` usa esta tabla cuando hay filas y la
 * columna cuando no.
 *
 * ## Qué NO es esto
 *
 * No sustituye a los recibos de cobro. Un recibo salda una cuenta por cobrar que
 * ya existe; esto registra el dinero que entra **en el mismo acto** de facturar,
 * cuando no llega a haber cuenta por cobrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();

            $table->string('method', 20)->comment('cash, card, transfer, check, other');

            // Dónde entra el dinero. En efectivo es la cuenta de la caja que
            // está abierta, y por eso el arqueo lo recoge sin plomería extra.
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            $table->decimal('amount', 18, 4);

            // Lo que el cliente entregó y lo que se le devolvió. Solo tienen
            // sentido en efectivo, y se guardan porque son lo que explica el
            // arqueo cuando el cajero recuerda mal.
            $table->decimal('tendered', 18, 4)->nullable();
            $table->decimal('change_given', 18, 4)->nullable();

            $table->string('reference', 100)->nullable()
                ->comment('Voucher de la tarjeta, número de transferencia o de cheque');

            $table->timestamps();

            $table->index(['company_id', 'sale_id']);
            $table->index(['company_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
