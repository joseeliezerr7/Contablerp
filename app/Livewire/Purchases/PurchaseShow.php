<?php

declare(strict_types=1);

namespace App\Livewire\Purchases;

use App\Domains\Purchases\Models\Purchase;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * La compra, de solo lectura.
 *
 * Mismo hueco que en ventas: una compra recibida es inmutable, la pantalla de
 * edición solo sirve borradores, y al recibirla el documento salía de la vista.
 * Peor que en ventas, porque la compra **no tiene PDF**: una vez recibida no
 * había absolutamente nada que mirar, ni siquiera qué se compró.
 */
#[Title('Compra')]
class PurchaseShow extends Component
{
    public int $purchaseId;

    public function mount(int $purchase): void
    {
        $model = Purchase::query()->findOrFail($purchase);

        $this->authorize('view', $model);

        $this->purchaseId = $model->id;
    }

    public function render(): View
    {
        $purchase = Purchase::query()
            ->with([
                'supplier',
                'branch:id,code,name',
                'warehouse:id,code,name',
                'paymentAccount:id,code,name',
                // `track_inventory` va en el select aunque no se muestre:
                // `PurchaseItem::goesToInventory()` lo lee, y con
                // `preventAccessingMissingAttributes` una columna que no se
                // trajo no devuelve null, revienta.
                'items.product:id,code,name,track_inventory',
                'items.tax:id,code,name',
                'items.expenseAccount:id,code,name',
                'payable.applications.payment:id,number,date',
            ])
            ->findOrFail($this->purchaseId);

        $this->authorize('view', $purchase);

        return view('livewire.purchases.purchase-show', [
            'purchase' => $purchase,
            'entry' => $purchase->journalEntry(),
        ]);
    }
}
