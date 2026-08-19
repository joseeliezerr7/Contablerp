<?php

declare(strict_types=1);

use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Inventory\DataTransfer\StockMovementDraft;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Exceptions\InsufficientStockException;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Inventory\Models\InventoryMovement;
use App\Domains\Inventory\Services\InventoryService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->inventory = app(InventoryService::class);
    $this->warehouse = warehouse();
    $this->product = makeProduct('100.00', tracked: true);
});

/**
 * Entrada de mercadería dentro de una transacción, como la haría un documento.
 */
function receiveStock(InventoryService $service, int $productId, int $warehouseId, string $quantity, string $value): void
{
    DB::transaction(fn () => $service->apply(StockMovementDraft::in(
        $productId, $warehouseId, $quantity, Money::of($value), MovementType::Purchase, '2026-03-01',
    )));
}

function issueStock(InventoryService $service, int $productId, int $warehouseId, string $quantity): void
{
    DB::transaction(fn () => $service->apply(StockMovementDraft::out(
        $productId, $warehouseId, $quantity, MovementType::Sale, '2026-03-05',
    )));
}

/*
|--------------------------------------------------------------------------
| Promedio ponderado móvil
|--------------------------------------------------------------------------
*/

it('deja el promedio en el costo de la primera compra', function () {
    receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '10', '1000.00');

    $stock = $this->inventory->stockFor($this->product->id, $this->warehouse->id);

    expect($stock->quantity)->toBe('10.000000')
        ->and($stock->total_value)->toBe('1000.0000')
        ->and($stock->average_cost)->toBe('100.000000');
});

it('pondera el promedio con la segunda compra a otro precio', function () {
    receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '10', '1000.00');
    receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '10', '1400.00');

    // 2400 / 20 = 120
    expect($this->inventory->averageCost($this->product->id, $this->warehouse->id)->toString())
        ->toBe('120.0000');
});

it('no altera el promedio al despachar', function () {
    receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '10', '1000.00');
    receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '10', '1400.00');

    issueStock($this->inventory, $this->product->id, $this->warehouse->id, '5');

    $stock = $this->inventory->stockFor($this->product->id, $this->warehouse->id);

    expect($stock->quantity)->toBe('15.000000')
        ->and($stock->total_value)->toBe('1800.0000')   // 2400 − 600
        ->and($stock->average_cost)->toBe('120.000000');
});

it('valoriza la salida al promedio vigente, no al costo de la última compra', function () {
    receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '10', '1000.00');
    receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '10', '1400.00');

    issueStock($this->inventory, $this->product->id, $this->warehouse->id, '5');

    $salida = InventoryMovement::query()->where('type', MovementType::Sale)->sole();

    expect($salida->valueAmount()->toString())->toBe('-600.0000')
        ->and($salida->unit_cost)->toBe('120.000000');
});

/*
|--------------------------------------------------------------------------
| El centavo que se pierde si el costeo está mal hecho
|--------------------------------------------------------------------------
*/

it('deja la existencia en cero exacto al despachar la última unidad', function () {
    // 100 entre 3 no reparte: el promedio es 33.333333…
    receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '3', '100.00');

    issueStock($this->inventory, $this->product->id, $this->warehouse->id, '1');
    issueStock($this->inventory, $this->product->id, $this->warehouse->id, '1');
    issueStock($this->inventory, $this->product->id, $this->warehouse->id, '1');

    $stock = $this->inventory->stockFor($this->product->id, $this->warehouse->id);

    expect($stock->quantity)->toBe('0.000000')
        ->and($stock->total_value)->toBe('0.0000');
});

it('reparte el costo de las tres salidas sin perder ni inventar centavos', function () {
    receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '3', '100.00');

    issueStock($this->inventory, $this->product->id, $this->warehouse->id, '1');
    issueStock($this->inventory, $this->product->id, $this->warehouse->id, '1');
    issueStock($this->inventory, $this->product->id, $this->warehouse->id, '1');

    $salidas = InventoryMovement::query()->where('type', MovementType::Sale)->get();

    $total = Money::sum($salidas->map(fn (InventoryMovement $m) => $m->valueAmount())->all());

    // Lo que salió tiene que ser exactamente lo que entró.
    expect($total->toString())->toBe('-100.0000')
        ->and($salidas)->toHaveCount(3);
});

it('mantiene el kardex igual a la existencia después de muchos movimientos', function () {
    receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '7', '333.33');
    issueStock($this->inventory, $this->product->id, $this->warehouse->id, '2');
    receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '11', '901.17');
    issueStock($this->inventory, $this->product->id, $this->warehouse->id, '9');
    receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '3', '77.77');
    issueStock($this->inventory, $this->product->id, $this->warehouse->id, '4');

    $stock = $this->inventory->stockFor($this->product->id, $this->warehouse->id);

    $kardex = Money::sum(
        InventoryMovement::query()->get()->map(fn (InventoryMovement $m) => $m->valueAmount())->all()
    );

    expect($kardex->toString())->toBe($stock->total_value)
        ->and($stock->quantity)->toBe('6.000000');
});

/*
|--------------------------------------------------------------------------
| Guardas
|--------------------------------------------------------------------------
*/

it('bloquea la salida cuando no hay existencia suficiente', function () {
    receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '5', '500.00');

    expect(fn () => issueStock($this->inventory, $this->product->id, $this->warehouse->id, '6'))
        ->toThrow(InsufficientStockException::class, 'No hay existencia suficiente');
});

it('deja la existencia intacta cuando la salida se rechaza', function () {
    receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '5', '500.00');

    try {
        issueStock($this->inventory, $this->product->id, $this->warehouse->id, '6');
    } catch (InsufficientStockException) {
        // Esperado.
    }

    $stock = $this->inventory->stockFor($this->product->id, $this->warehouse->id);

    expect($stock->quantity)->toBe('5.000000')
        ->and(InventoryMovement::query()->count())->toBe(1);
});

it('rechaza mover un producto que no lleva control de existencias', function () {
    $servicio = makeProduct('100.00');

    expect(fn () => receiveStock($this->inventory, $servicio->id, $this->warehouse->id, '1', '100.00'))
        ->toThrow(InventoryException::class, 'no lleva control de existencias');
});

it('deshace el movimiento si el documento falla después de registrarlo', function () {
    // El motivo de exigir transacción: la mercadería y su documento entran o no
    // entran juntos. (Que el servicio *rechace* correr fuera de una transacción
    // no se puede comprobar aquí, porque `RefreshDatabase` ya abre una.)
    receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '10', '1000.00');

    try {
        DB::transaction(function (): void {
            $this->inventory->apply(StockMovementDraft::in(
                $this->product->id, $this->warehouse->id, '5', Money::of('700.00'),
                MovementType::Purchase, '2026-03-02',
            ));

            throw new RuntimeException('El documento falló después de mover el inventario');
        });
    } catch (RuntimeException) {
        // Esperado.
    }

    $stock = $this->inventory->stockFor($this->product->id, $this->warehouse->id);

    expect($stock->quantity)->toBe('10.000000')
        ->and($stock->total_value)->toBe('1000.0000')
        ->and(InventoryMovement::query()->count())->toBe(1);
});

it('rechaza una cantidad que no es positiva', function () {
    expect(fn () => receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '0', '0'))
        ->toThrow(InventoryException::class, 'mayor que cero');
});

/*
|--------------------------------------------------------------------------
| Kardex y aislamiento
|--------------------------------------------------------------------------
*/

it('guarda los saldos corridos del kardex', function () {
    receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '10', '1000.00');
    issueStock($this->inventory, $this->product->id, $this->warehouse->id, '4');

    $movimientos = InventoryMovement::query()->inKardexOrder()->get();

    expect($movimientos[0]->balance_quantity)->toBe('10.000000')
        ->and($movimientos[0]->balance_value)->toBe('1000.0000')
        ->and($movimientos[1]->balance_quantity)->toBe('6.000000')
        ->and($movimientos[1]->balance_value)->toBe('600.0000');
});

it('lleva existencias separadas por bodega', function () {
    $otra = warehouse('BOD2');

    receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '10', '1000.00');
    receiveStock($this->inventory, $this->product->id, $otra->id, '10', '1400.00');

    expect($this->inventory->averageCost($this->product->id, $this->warehouse->id)->toString())->toBe('100.0000')
        ->and($this->inventory->averageCost($this->product->id, $otra->id)->toString())->toBe('140.0000');
});

it('aísla las existencias entre empresas', function () {
    receiveStock($this->inventory, $this->product->id, $this->warehouse->id, '10', '1000.00');

    $otra = accountingCompany();
    actingAsUserOf($otra, role: PermissionCatalog::ACCOUNTANT);

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and($this->inventory->totalValue()->isZero())->toBeTrue()
        ->and(acrossCompanies(fn () => InventoryMovement::acrossCompanies()->count()))->toBe(1);
});
