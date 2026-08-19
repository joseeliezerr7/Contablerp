<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domains\Inventory\Models\StockTransfer;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * El traslado entre bodegas, de solo lectura.
 *
 * A diferencia del ajuste, un traslado **no cambia el patrimonio**: la misma
 * mercadería sale de una bodega y entra a otra al mismo costo. Por eso puede no
 * tener partida contable, y la pantalla lo dice en vez de dejar el hueco.
 */
#[Title('Traslado entre bodegas')]
class TransferShow extends Component
{
    public int $transferId;

    public function mount(int $transfer): void
    {
        $model = StockTransfer::query()->findOrFail($transfer);

        $this->authorize('view', $model);

        $this->transferId = $model->id;
    }

    public function render(): View
    {
        $transfer = StockTransfer::query()
            ->with([
                'branch:id,code,name',
                'fromWarehouse:id,code,name',
                'toWarehouse:id,code,name',
                'items.product:id,code,name',
            ])
            ->findOrFail($this->transferId);

        $this->authorize('view', $transfer);

        return view('livewire.inventory.transfer-show', [
            'transfer' => $transfer,
        ]);
    }
}
