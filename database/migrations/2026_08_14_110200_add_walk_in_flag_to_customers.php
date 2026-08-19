<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cliente predeterminado del mostrador.
 *
 * El punto de venta necesita a quién facturarle cuando el cliente no se
 * identifica, que es casi siempre. Adivinarlo —el primero de la lista, el que se
 * llame «mostrador»— es lo que hace que un día el mostrador empiece a facturarle
 * todo a una constructora que compró una vez.
 *
 * Es una bandera y no una columna en `companies` porque el cliente ya existe
 * como registro: lo que faltaba era decir cuál.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->boolean('is_walk_in')->default(false)->after('is_active')
                ->comment('Cliente por defecto del punto de venta');
        });

        // Uno solo por empresa: dos «clientes de mostrador» dejarían la venta
        // rápida dependiendo del orden de la tabla, que es de donde venimos.
        //
        // La columna generada vale 1 o NULL y **no menciona `company_id`**:
        // MySQL prohíbe que una clave foránea con ON DELETE sea columna base de
        // una generada STORED, y `customers.company_id` cascadea. La empresa va
        // en el índice, que es donde hace falta para que el único sea por
        // empresa. Es la misma solución que en `fiscal_authorizations`.
        DB::statement(<<<'SQL'
            ALTER TABLE customers
            ADD COLUMN walk_in_key TINYINT UNSIGNED
            GENERATED ALWAYS AS (CASE WHEN is_walk_in = 1 THEN 1 END) STORED,
            ADD UNIQUE INDEX customers_walk_in_unique (company_id, walk_in_key)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customers DROP INDEX customers_walk_in_unique');
        DB::statement('ALTER TABLE customers DROP COLUMN walk_in_key');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('is_walk_in');
        });
    }
};
