<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Importe acreditado por notas de crédito.
 *
 * Va en su propia columna y **no** dentro de `paid_amount`. Una nota de crédito
 * no es un cobro: no entró dinero. Sumarla a lo cobrado inflaría la recaudación,
 * le daría al vendedor una comisión sobre una devolución y haría que el flujo de
 * efectivo mostrara entradas que nunca ocurrieron.
 *
 * El saldo pasa a ser `original - cobrado - acreditado`, y sigue siendo una
 * columna generada por la misma razón de siempre: un saldo escrito a mano acaba
 * mintiendo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La columna generada depende de las que se van a modificar, así que hay
        // que quitarla antes y volver a crearla después.
        DB::statement('ALTER TABLE receivables DROP INDEX receivables_balance_index');
        DB::statement('ALTER TABLE receivables DROP COLUMN balance');
        DB::statement('ALTER TABLE receivables DROP CONSTRAINT receivables_paid_within_original');

        DB::statement(<<<'SQL'
            ALTER TABLE receivables
            ADD COLUMN credited_amount DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER paid_amount
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE receivables
            ADD COLUMN balance DECIMAL(18,4)
            GENERATED ALWAYS AS (original_amount - paid_amount - credited_amount) STORED,
            ADD INDEX receivables_balance_index (balance)
        SQL);

        // Ni se cobra de más, ni se acredita de más, ni entre las dos cosas
        // pueden superar el documento.
        DB::statement(<<<'SQL'
            ALTER TABLE receivables
            ADD CONSTRAINT receivables_paid_within_original
            CHECK (
                paid_amount >= 0
                AND credited_amount >= 0
                AND original_amount > 0
                AND paid_amount + credited_amount <= original_amount
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE receivables DROP INDEX receivables_balance_index');
        DB::statement('ALTER TABLE receivables DROP COLUMN balance');
        DB::statement('ALTER TABLE receivables DROP CONSTRAINT receivables_paid_within_original');
        DB::statement('ALTER TABLE receivables DROP COLUMN credited_amount');

        DB::statement(<<<'SQL'
            ALTER TABLE receivables
            ADD COLUMN balance DECIMAL(18,4)
            GENERATED ALWAYS AS (original_amount - paid_amount) STORED,
            ADD INDEX receivables_balance_index (balance)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE receivables
            ADD CONSTRAINT receivables_paid_within_original
            CHECK (paid_amount >= 0 AND original_amount > 0 AND paid_amount <= original_amount)
        SQL);
    }
};
