<?php

declare(strict_types=1);

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Inventory\Enums\AdjustmentReason;
use App\Domains\Inventory\Models\InventoryMovement;
use App\Domains\Inventory\Models\InventoryStock;
use App\Domains\Inventory\Services\InventoryService;
use App\Domains\Inventory\Services\StockAdjustmentService;
use App\Domains\Inventory\Services\StockTransferService;
use App\Domains\Purchases\Models\Purchase;
use App\Domains\Purchases\Services\PurchaseService;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Criterio de aceptación de la Fase 5: **el kardex valorizado tiene que dar el
 * saldo de la cuenta contable de inventario.**
 *
 * Son dos registros independientes de la misma realidad, escritos por caminos
 * distintos: el kardex por `InventoryService`, la cuenta por `AccountingEngine`.
 * Que coincidan no está garantizado por ninguna llave foránea; lo garantiza que
 * ambos muevan exactamente el mismo importe en cada operación. Esta prueba
 * ejercita todas las operaciones que existen —comprar, vender, ajustar,
 * trasladar y anular cada una— y compara los dos números al final.
 *
 * Si una fase futura añade una operación que mueve existencias, esta prueba es
 * la que avisa cuando se le olvide mover el valor.
 */
beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();

    $this->sales = app(SaleService::class);
    $this->purchases = app(PurchaseService::class);
    $this->adjustments = app(StockAdjustmentService::class);
    $this->transfers = app(StockTransferService::class);
    $this->inventory = app(InventoryService::class);

    $this->branch = mainBranch();
    $this->main = warehouse();
    $this->second = warehouse('BOD2');
    $this->supplier = makeSupplier();
    $this->customer = makeCustomer(['credit_limit' => '9000000.00', 'credit_days' => 30]);
});

/**
 * Recorre todas las operaciones que mueven existencias, con importes que no
 * reparten en partes iguales para que cualquier redondeo mal hecho salte.
 */
function exerciseEveryStockPath(object $ctx): void
{
    $cafe = makeProduct('250.00', tracked: true);
    $azucar = makeProduct('45.00', tracked: true);
    $flete = makeProduct('300.00');   // servicio: no toca inventario

    $buy = function (array $lines, string $invoice) use ($ctx) {
        return $ctx->purchases->createAndReceive([
            'branch_id' => $ctx->branch->id,
            'warehouse_id' => $ctx->main->id,
            'supplier_id' => $ctx->supplier->id,
            'supplier_invoice_number' => $invoice,
            'date' => '2026-04-01',
            'payment_condition' => PaymentCondition::Credit,
            'credit_days' => 30,
        ], $lines);
    };

    $sell = fn (array $lines) => $ctx->sales->createAndIssue([
        'branch_id' => $ctx->branch->id,
        'warehouse_id' => $ctx->main->id,
        'customer_id' => $ctx->customer->id,
        'date' => '2026-04-10',
        'payment_condition' => PaymentCondition::Cash,
        'deposit_account_id' => account('1.1.02.01')->id,
    ], $lines);

    // Compras a precios que no reparten.
    $buy([
        ['product_id' => $cafe->id, 'quantity' => '7', 'unit_price' => '233.33'],
        ['product_id' => $azucar->id, 'quantity' => '13', 'unit_price' => '41.77'],
    ], 'FAC-K-01');

    $buy([
        ['product_id' => $cafe->id, 'quantity' => '11', 'unit_price' => '251.11', 'discount_rate' => '7'],
        ['product_id' => $flete->id, 'quantity' => '1', 'unit_price' => '900.00'],
    ], 'FAC-K-02');

    // Ventas, incluida una que se lleva mercadería y servicio en la misma
    // factura.
    $sell([
        ['product_id' => $cafe->id, 'quantity' => '5', 'unit_price' => '400.00'],
        ['product_id' => $flete->id, 'quantity' => '1', 'unit_price' => '300.00'],
    ]);

    $ventaAnulada = $sell([
        ['product_id' => $azucar->id, 'quantity' => '4', 'unit_price' => '80.00', 'discount_rate' => '10'],
    ]);

    // Ajustes en los dos sentidos.
    $ctx->adjustments->createAndPost([
        'branch_id' => $ctx->branch->id,
        'warehouse_id' => $ctx->main->id,
        'date' => '2026-04-15',
        'reason' => AdjustmentReason::Damage,
    ], [['product_id' => $cafe->id, 'quantity' => '-3']]);

    $ajusteAnulado = $ctx->adjustments->createAndPost([
        'branch_id' => $ctx->branch->id,
        'warehouse_id' => $ctx->main->id,
        'date' => '2026-04-16',
        'reason' => AdjustmentReason::Count,
    ], [['product_id' => $azucar->id, 'quantity' => '2']]);

    // Traslado entre bodegas, y otro que se anula.
    $ctx->transfers->createAndPost([
        'branch_id' => $ctx->branch->id,
        'from_warehouse_id' => $ctx->main->id,
        'to_warehouse_id' => $ctx->second->id,
        'date' => '2026-04-20',
    ], [['product_id' => $cafe->id, 'quantity' => '3']]);

    $trasladoAnulado = $ctx->transfers->createAndPost([
        'branch_id' => $ctx->branch->id,
        'from_warehouse_id' => $ctx->main->id,
        'to_warehouse_id' => $ctx->second->id,
        'date' => '2026-04-21',
    ], [['product_id' => $azucar->id, 'quantity' => '6']]);

    // Y las anulaciones de cada tipo de documento.
    $ctx->sales->void($ventaAnulada, 'Anulada en la prueba de invariante');
    $ctx->adjustments->void($ajusteAnulado, 'Anulado en la prueba de invariante');
    $ctx->transfers->void($trasladoAnulado, 'Anulado en la prueba de invariante');

    $compraAnulada = $buy(
        [['product_id' => $azucar->id, 'quantity' => '5', 'unit_price' => '39.99']],
        'FAC-K-03',
    );
    $ctx->purchases->void($compraAnulada, 'Anulada en la prueba de invariante');
}

it('cuadra el kardex valorizado contra la cuenta contable de inventario', function () {
    exerciseEveryStockPath($this);

    $kardex = $this->inventory->totalValue();
    $contable = ledgerBalanceOf('1.1.04.01');

    expect($kardex->isPositive())->toBeTrue('La prueba no dejó existencias')
        ->and($contable->equals($kardex))->toBeTrue(
            "El inventario descuadra: contabilidad {$contable->format()}, kardex {$kardex->format()}."
        );
});

it('cuadra la suma de los movimientos contra las existencias', function () {
    exerciseEveryStockPath($this);

    // El kardex es el libro; las existencias son su materialización. Sumar el
    // libro entero tiene que dar lo mismo que leer el resumen.
    $desdeMovimientos = Money::sum(
        InventoryMovement::query()->get()->map(fn (InventoryMovement $m) => $m->valueAmount())->all()
    );

    expect($desdeMovimientos->equals($this->inventory->totalValue()))->toBeTrue(
        "El libro dice {$desdeMovimientos->format()} y las existencias {$this->inventory->totalValue()->format()}."
    );
});

it('cuadra producto por producto y bodega por bodega', function () {
    exerciseEveryStockPath($this);

    $existencias = InventoryStock::query()->get();

    expect($existencias)->not->toBeEmpty();

    foreach ($existencias as $stock) {
        $movimientos = InventoryMovement::query()
            ->forProduct($stock->product_id, $stock->warehouse_id)
            ->get();

        $cantidad = array_reduce(
            $movimientos->all(),
            fn (string $carry, InventoryMovement $m) => bcadd($carry, (string) $m->quantity, 6),
            '0',
        );

        $valor = Money::sum($movimientos->map(fn (InventoryMovement $m) => $m->valueAmount())->all());

        expect(bccomp($cantidad, (string) $stock->quantity, 6))->toBe(
            0, "Cantidad distinta en producto {$stock->product_id}, bodega {$stock->warehouse_id}."
        )->and($valor->toString())->toBe(
            $stock->total_value, "Valor distinto en producto {$stock->product_id}, bodega {$stock->warehouse_id}."
        );
    }
});

it('no deja existencias negativas por ningún camino', function () {
    exerciseEveryStockPath($this);

    $negativas = InventoryStock::query()->where('quantity', '<', 0)->get();

    expect($negativas)->toBeEmpty(
        'Existencias en negativo: '.$negativas->pluck('product_id')->implode(', ')
    );
});

it('deja el saldo corrido de cada movimiento igual al recalculado', function () {
    exerciseEveryStockPath($this);

    // Los saldos corridos se guardan para imprimir el kardex sin recalcular.
    // Aquí se recalculan y se comparan: si divergen, el kardex impreso miente.
    $grupos = InventoryMovement::query()->inKardexOrder()->get()
        ->groupBy(fn (InventoryMovement $m) => $m->product_id.':'.$m->warehouse_id);

    foreach ($grupos as $clave => $movimientos) {
        $cantidad = '0';
        $valor = Money::zero();

        foreach ($movimientos as $movimiento) {
            $cantidad = bcadd($cantidad, (string) $movimiento->quantity, 6);
            $valor = $valor->plus($movimiento->valueAmount());

            expect(bccomp($cantidad, (string) $movimiento->balance_quantity, 6))
                ->toBe(0, "Saldo de cantidad corrido mal en {$clave}, movimiento {$movimiento->id}.")
                ->and($valor->toString())
                ->toBe($movimiento->balance_value, "Saldo de valor corrido mal en {$clave}, movimiento {$movimiento->id}.");
        }
    }
});

it('vuelve a cero cuando se anula absolutamente todo', function () {
    $producto = makeProduct('100.00', tracked: true);

    $compra = $this->purchases->createAndReceive([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $this->main->id,
        'supplier_id' => $this->supplier->id,
        'supplier_invoice_number' => 'FAC-Z-01',
        'date' => '2026-04-01',
        'payment_condition' => PaymentCondition::Credit,
        'credit_days' => 30,
    ], [['product_id' => $producto->id, 'quantity' => '9', 'unit_price' => '77.77']]);

    $venta = $this->sales->createAndIssue([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $this->main->id,
        'customer_id' => $this->customer->id,
        'date' => '2026-04-05',
        'payment_condition' => PaymentCondition::Cash,
        'deposit_account_id' => account('1.1.02.01')->id,
    ], [['product_id' => $producto->id, 'quantity' => '4', 'unit_price' => '150.00']]);

    $this->sales->void($venta, 'Anulada');
    $this->purchases->void($compra->refresh(), 'Anulada');

    expect($this->inventory->totalValue()->isZero())->toBeTrue()
        ->and(ledgerBalanceOf('1.1.04.01')->isZero())->toBeTrue()
        ->and($this->inventory->availableQuantity($producto->id, $this->main->id))->toBe('0.000000');
});

it('mantiene el libro contable cuadrado con el inventario en marcha', function () {
    exerciseEveryStockPath($this);

    // La invariante de la Fase 1 sigue en pie después de todo lo anterior.
    $totales = DB::table('journal_entry_lines as l')
        ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
        ->where('e.status', JournalEntryStatus::Posted->value)
        ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
        ->first();

    expect(Money::of((string) $totales->debit)->equals(Money::of((string) $totales->credit)))->toBeTrue();
});

it('aísla el inventario entre empresas', function () {
    exerciseEveryStockPath($this);

    $otra = accountingCompany();
    actingAsUserOf($otra, role: PermissionCatalog::ACCOUNTANT);

    expect($this->inventory->totalValue()->isZero())->toBeTrue()
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(Sale::query()->count())->toBe(0)
        ->and(Purchase::query()->count())->toBe(0)
        ->and(acrossCompanies(fn () => InventoryMovement::acrossCompanies()->count()))->toBeGreaterThan(0);
});
