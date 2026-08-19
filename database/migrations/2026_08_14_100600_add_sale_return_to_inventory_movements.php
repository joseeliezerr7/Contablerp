<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Movimientos por nota de crédito.
 *
 * `sale_return` no es lo mismo que `sale_void`, aunque las dos reingresen
 * mercadería. La anulación borra una venta que nunca debió existir; la
 * devolución reconoce que la venta ocurrió y que el cliente trajo el producto
 * de vuelta. Quien lee un kardex necesita distinguirlas: un producto que se
 * devuelve mucho es un problema de calidad, y uno que se anula mucho es un
 * problema de facturación.
 */
return new class extends Migration
{
    private const TYPES = [
        'opening', 'purchase', 'sale', 'purchase_void', 'sale_void',
        'adjustment_in', 'adjustment_out', 'transfer_in', 'transfer_out',
        'sale_return', 'sale_return_void',
    ];

    public function up(): void
    {
        $this->setEnum(self::TYPES);
    }

    public function down(): void
    {
        DB::table('inventory_movements')
            ->whereIn('type', ['sale_return', 'sale_return_void'])
            ->delete();

        $this->setEnum(array_slice(self::TYPES, 0, 9));
    }

    /**
     * @param  array<int, string>  $types
     */
    private function setEnum(array $types): void
    {
        $values = implode(',', array_map(fn (string $t) => "'".$t."'", $types));

        DB::statement("ALTER TABLE inventory_movements MODIFY COLUMN type ENUM({$values}) NOT NULL");
    }
};
