<?php

declare(strict_types=1);

namespace App\Livewire\Treasury;

use App\Domains\Payables\Models\Payment;
use App\Domains\Treasury\Models\Check;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * El cheque, de solo lectura.
 *
 * Lo que el listado no podía contar es de dónde salió. Un cheque casi siempre
 * nace de un pago a proveedor, y esa relación se guarda por
 * `source_type`/`source_id` —sin relación Eloquent que seguir—, así que quien
 * preguntaba «¿este cheque de quince mil, a qué factura corresponde?» se
 * quedaba sin respuesta.
 */
#[Title('Cheque')]
class CheckShow extends Component
{
    public int $checkId;

    public function mount(int $check): void
    {
        $model = Check::query()->findOrFail($check);

        $this->authorize('view', $model);

        $this->checkId = $model->id;
    }

    public function render(): View
    {
        $check = Check::query()
            // Sin recortar columnas: `BankAccount::label()` usa `alias`,
            // `bank_name` y `number`, y una columna que no se trae revienta con
            // `preventAccessingMissingAttributes`.
            ->with('bankAccount')
            ->findOrFail($this->checkId);

        $this->authorize('view', $check);

        return view('livewire.treasury.check-show', [
            'check' => $check,
            'payment' => $this->originPayment($check),
        ]);
    }

    /**
     * El pago que lo originó, si lo hubo.
     *
     * Un cheque también puede girarse suelto —un anticipo, un gasto sin factura
     * todavía—, y entonces `source_type` viene vacío.
     */
    private function originPayment(Check $check): ?Payment
    {
        if ($check->source_type !== Payment::SOURCE_TYPE || $check->source_id === null) {
            return null;
        }

        return Payment::query()
            ->with('supplier:id,code,name')
            ->find($check->source_id);
    }
}
