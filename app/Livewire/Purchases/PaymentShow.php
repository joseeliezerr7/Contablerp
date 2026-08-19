<?php

declare(strict_types=1);

namespace App\Livewire\Purchases;

use App\Domains\Assets\Models\Withholding;
use App\Domains\Payables\Models\Payment;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * El pago a proveedor, de solo lectura.
 *
 * Es el espejo del recibo de cobro y tenía el mismo problema: del listado solo
 * salía «anular», y el monto por sí solo no dice contra qué facturas se aplicó
 * ni cuánto se le retuvo al proveedor.
 */
#[Title('Pago a proveedor')]
class PaymentShow extends Component
{
    public int $paymentId;

    public function mount(int $payment): void
    {
        $model = Payment::query()->findOrFail($payment);

        $this->authorize('view', $model);

        $this->paymentId = $model->id;
    }

    public function render(): View
    {
        $payment = Payment::query()
            ->with([
                'supplier',
                'branch:id,code,name',
                'paymentAccount:id,code,name',
                'applications.payable.purchase:id,number',
            ])
            ->findOrFail($this->paymentId);

        $this->authorize('view', $payment);

        return view('livewire.purchases.payment-show', [
            'payment' => $payment,
            // Las retenciones se guardan aparte, apuntando al documento por
            // `source_type`/`source_id`: no hay relación Eloquent que seguir.
            'withholdings' => Withholding::query()
                ->with('type:id,code,name')
                ->where('source_type', Payment::SOURCE_TYPE)
                ->where('source_id', $payment->id)
                ->get(),
            'entry' => $payment->journalEntry(),
        ]);
    }
}
