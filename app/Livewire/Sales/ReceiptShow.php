<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Domains\Receivables\Models\Receipt;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * El recibo de cobro, de solo lectura.
 *
 * Era el peor de los huecos: una factura al menos se podía imprimir, pero un
 * recibo emitido no se podía ver de ninguna forma. Y es justo el documento del
 * que más se pregunta —«¿este abono de diez mil, contra qué facturas se
 * aplicó?»—, porque el monto solo no responde nada.
 */
#[Title('Recibo de cobro')]
class ReceiptShow extends Component
{
    public int $receiptId;

    /**
     * El id se busca aquí y no por enlace de ruta: `SubstituteBindings` corre
     * antes del middleware que activa la empresa.
     */
    public function mount(int $receipt): void
    {
        $model = Receipt::query()->findOrFail($receipt);

        $this->authorize('view', $model);

        $this->receiptId = $model->id;
    }

    public function render(): View
    {
        $receipt = Receipt::query()
            ->with([
                'customer',
                'branch:id,code,name',
                'depositAccount:id,code,name',
                'applications.receivable.sale:id,number',
            ])
            ->findOrFail($this->receiptId);

        $this->authorize('view', $receipt);

        return view('livewire.sales.receipt-show', [
            'receipt' => $receipt,
            'entry' => $receipt->journalEntry(),
        ]);
    }
}
