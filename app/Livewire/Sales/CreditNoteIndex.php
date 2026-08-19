<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Domains\Fiscal\Exceptions\FiscalException;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\CreditNote;
use App\Domains\Sales\Services\CreditNoteService;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Notas de crédito')]
class CreditNoteIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'estado', except: '')]
    public string $statusFilter = '';

    public ?int $voiding = null;

    public string $voidReason = '';

    public ?int $issuing = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter'], strict: true)) {
            $this->resetPage();
        }
    }

    public function confirmIssue(int $id): void
    {
        $note = CreditNote::query()->findOrFail($id);
        $this->authorize('issue', $note);

        $this->issuing = $id;
    }

    public function issue(CreditNoteService $service): void
    {
        $note = CreditNote::query()->findOrFail($this->issuing);
        $this->authorize('issue', $note);

        try {
            $service->issue($note);
            session()->flash('success', 'Nota de crédito emitida.');
            $this->issuing = null;
        } catch (SalesException|FiscalException $e) {
            $this->addError('issuing', $e->getMessage());
        }
    }

    public function confirmVoid(int $id): void
    {
        $note = CreditNote::query()->findOrFail($id);
        $this->authorize('void', $note);

        $this->voiding = $id;
        $this->voidReason = '';
    }

    public function void(CreditNoteService $service): void
    {
        $this->validate([
            'voidReason' => ['required', 'string', 'min:5', 'max:500'],
        ], attributes: ['voidReason' => 'motivo']);

        $note = CreditNote::query()->findOrFail($this->voiding);
        $this->authorize('void', $note);

        try {
            $service->void($note, $this->voidReason);
            session()->flash('success', 'Nota de crédito anulada.');
            $this->cancelAction();
        } catch (SalesException $e) {
            $this->addError('voidReason', $e->getMessage());
        }
    }

    public function deleteDraft(int $id, CreditNoteService $service): void
    {
        $note = CreditNote::query()->findOrFail($id);
        $this->authorize('delete', $note);

        $service->deleteDraft($note);

        session()->flash('success', 'Borrador eliminado.');
    }

    public function cancelAction(): void
    {
        $this->reset(['voiding', 'voidReason', 'issuing']);
        $this->resetValidation();
    }

    public function render(): View
    {
        $this->authorize('viewAny', CreditNote::class);

        $notes = CreditNote::query()
            ->with(['customer:id,name', 'sale:id,number'])
            ->when($this->search !== '', fn ($q) => $q->where(
                fn ($sub) => $sub->where('number', 'like', '%'.$this->search.'%')
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', '%'.$this->search.'%'))
                    ->orWhereHas('sale', fn ($s) => $s->where('number', 'like', '%'.$this->search.'%'))
            ))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.sales.credit-note-index', [
            'notes' => $notes,
            'statuses' => SaleStatus::cases(),
        ]);
    }
}
