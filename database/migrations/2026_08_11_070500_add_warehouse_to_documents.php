<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bodega de origen y destino en los documentos comerciales.
 *
 * Va en la cabecera y no en cada línea: un documento se despacha o se recibe en
 * una bodega, y permitir una bodega distinta por línea complica la captura para
 * resolver un caso que en la práctica se maneja con dos documentos.
 *
 * Es nullable porque hay documentos que no mueven existencias —una factura de
 * solo servicios, una compra de honorarios— y obligarlos a declarar bodega
 * sería pedir un dato que no significa nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->after('branch_id')
                ->constrained()->restrictOnDelete();
        });

        Schema::table('purchases', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->after('branch_id')
                ->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('warehouse_id');
        });

        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
