<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domains\Inventory\Models\StockAdjustment;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * El ajuste de inventario, de solo lectura.
 *
 * Un ajuste aplicado mueve el kardex y la contabilidad a la vez, y es el
 * documento por el que más se pregunta cuando el inventario no cuadra: «¿qué se
 * dio de baja en el conteo de marzo?». Aplicado, salía de la vista.
 */
#[Title('Ajuste de inventario')]
class AdjustmentShow extends Component
{
    public int $adjustmentId;

    public function mount(int $adjustment): void
    {
        $model = StockAdjustment::query()->findOrFail($adjustment);

        $this->authorize('view', $model);

        $this->adjustmentId = $model->id;
    }

    public function render(): View
    {
        $adjustment = StockAdjustment::query()
            ->with([
                'branch:id,code,name',
                'warehouse:id,code,name',
                'adjustmentAccount:id,code,name',
                'items.product:id,code,name',
            ])
            ->findOrFail($this->adjustmentId);

        $this->authorize('view', $adjustment);

        return view('livewire.inventory.adjustment-show', [
            'adjustment' => $adjustment,
            'entry' => $adjustment->journalEntry(),
        ]);
    }
}
