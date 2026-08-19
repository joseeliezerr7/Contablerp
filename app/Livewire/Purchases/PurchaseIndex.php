<?php

declare(strict_types=1);

namespace App\Livewire\Purchases;

use App\Domains\Purchases\Enums\PurchaseStatus;
use App\Domains\Purchases\Exceptions\PurchaseException;
use App\Domains\Purchases\Models\Purchase;
use App\Domains\Purchases\Services\PurchaseService;
use App\Support\Money;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Compras')]
class PurchaseIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'estado', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'desde', except: '')]
    public string $from = '';

    #[Url(as: 'hasta', except: '')]
    public string $to = '';

    public ?int $voidingId = null;

    public string $voidReason = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'from', 'to'], strict: true)) {
            $this->resetPage();
        }
    }

    public function receive(int $id, PurchaseService $purchases): void
    {
        $purchase = Purchase::query()->findOrFail($id);
        $this->authorize('receive', $purchase);

        try {
            $received = $purchases->receive($purchase);
            session()->flash('success', "Compra {$received->number} registrada.");
        } catch (PurchaseException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function confirmVoid(int $id): void
    {
        $purchase = Purchase::query()->findOrFail($id);
        $this->authorize('void', $purchase);

        $this->voidingId = $id;
        $this->voidReason = '';
    }

    public function void(PurchaseService $purchases): void
    {
        $this->validate([
            'voidReason' => ['required', 'string', 'min:5', 'max:500'],
        ], attributes: ['voidReason' => 'motivo']);

        $purchase = Purchase::query()->findOrFail($this->voidingId);
        $this->authorize('void', $purchase);

        try {
            $purchases->void($purchase, $this->voidReason);
            session()->flash('success', "Compra {$purchase->number} anulada.");
        } catch (PurchaseException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->cancelVoid();
    }

    public function delete(int $id, PurchaseService $purchases): void
    {
        $purchase = Purchase::query()->findOrFail($id);
        $this->authorize('delete', $purchase);

        try {
            $purchases->deleteDraft($purchase);
            session()->flash('success', 'Borrador eliminado.');
        } catch (PurchaseException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function cancelVoid(): void
    {
        $this->reset(['voidingId', 'voidReason']);
        $this->resetValidation();
    }

    public function render(): View
    {
        $this->authorize('viewAny', Purchase::class);

        $query = Purchase::query()
            ->with(['supplier:id,code,name', 'payable'])
            ->when($this->search !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('number', 'like', '%'.$this->search.'%')
                    ->orWhere('supplier_invoice_number', 'like', '%'.$this->search.'%')
                    ->orWhereHas('supplier', fn ($s) => $s->search($this->search))
            ))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->from !== '', fn ($q) => $q->where('date', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->where('date', '<=', $this->to));

        $receivedTotal = (clone $query)->where('status', PurchaseStatus::Received)->sum('total');

        return view('livewire.purchases.purchase-index', [
            'purchases' => $query->orderByDesc('date')->orderByDesc('id')->paginate(25),
            'statuses' => PurchaseStatus::cases(),
            'receivedTotal' => Money::of((string) $receivedTotal),
        ]);
    }
}
