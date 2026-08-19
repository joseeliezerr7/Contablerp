<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Domains\Sales\Models\Sale;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * La factura, de solo lectura.
 *
 * Faltaba desde la Fase 3 y el hueco venía del propio diseño: un documento
 * emitido es inmutable, así que la pantalla de edición solo sirve borradores.
 * Al emitirlo desaparecía de la vista, y la única forma de mirarlo era bajarse
 * el PDF —que es un documento fiscal para el cliente, no una pantalla de
 * consulta—.
 *
 * Aquí se ve todo lo que la factura movió: sus renglones, cómo se cobró, qué
 * quedó por cobrar y con qué partida contable se registró.
 */
#[Title('Factura')]
class SaleShow extends Component
{
    public int $saleId;

    /**
     * Recibe el id y busca el modelo aquí dentro, no por enlace de ruta: el
     * enlace corre en `SubstituteBindings`, antes del middleware que activa la
     * empresa, y la consulta saldría sin contexto.
     */
    public function mount(int $sale): void
    {
        $model = Sale::query()->findOrFail($sale);

        $this->authorize('view', $model);

        $this->saleId = $model->id;
    }

    public function render(): View
    {
        $sale = Sale::query()
            ->with([
                'customer',
                'branch:id,code,name',
                'warehouse:id,code,name',
                'items.product:id,code,name',
                'items.tax:id,code,name,rate',
                'payments.account:id,code,name',
                'receivable.applications.receipt:id,number,date',
            ])
            ->findOrFail($this->saleId);

        $this->authorize('view', $sale);

        return view('livewire.sales.sale-show', [
            'sale' => $sale,
            // La partida se busca por `source_type`/`source_id`, no hay
            // relación Eloquent; puede no existir si la factura es borrador.
            'entry' => $sale->journalEntry(),
        ]);
    }
}
