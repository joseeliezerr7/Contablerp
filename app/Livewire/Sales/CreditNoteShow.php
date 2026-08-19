<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Domains\Sales\Models\CreditNote;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * La nota de crédito, de solo lectura.
 *
 * Tiene su propia autorización del SAR —numeración distinta a la de la factura,
 * porque son dos documentos fiscales distintos— y esos datos también se congelan
 * al emitirla. Aquí se ven junto con la factura que acredita y el efecto que
 * tuvo sobre su saldo.
 */
#[Title('Nota de crédito')]
class CreditNoteShow extends Component
{
    public int $creditNoteId;

    public function mount(int $creditNote): void
    {
        $model = CreditNote::query()->findOrFail($creditNote);

        $this->authorize('view', $model);

        $this->creditNoteId = $model->id;
    }

    public function render(): View
    {
        $note = CreditNote::query()
            ->with([
                'customer',
                'branch:id,code,name',
                'warehouse:id,code,name',
                'sale:id,number,total',
                'sale.receivable',
                'items.product:id,code,name',
                'items.tax:id,code,name',
            ])
            ->findOrFail($this->creditNoteId);

        $this->authorize('view', $note);

        return view('livewire.sales.credit-note-show', [
            'note' => $note,
            'entry' => $note->journalEntry(),
        ]);
    }
}
