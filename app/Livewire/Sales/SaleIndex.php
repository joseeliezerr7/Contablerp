<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleService;
use App\Support\Money;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Facturas de venta')]
class SaleIndex extends Component
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

    public function issue(int $id, SaleService $sales): void
    {
        $sale = Sale::query()->findOrFail($id);
        $this->authorize('issue', $sale);

        try {
            $issued = $sales->issue($sale, auth()->user()->can('sales.invoices.override_credit_limit'));
            session()->flash('success', "Factura {$issued->number} emitida.");
        } catch (SalesException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function confirmVoid(int $id): void
    {
        $sale = Sale::query()->findOrFail($id);
        $this->authorize('void', $sale);

        $this->voidingId = $id;
        $this->voidReason = '';
    }

    public function void(SaleService $sales): void
    {
        $this->validate([
            'voidReason' => ['required', 'string', 'min:5', 'max:500'],
        ], attributes: ['voidReason' => 'motivo']);

        $sale = Sale::query()->findOrFail($this->voidingId);
        $this->authorize('void', $sale);

        try {
            $sales->void($sale, $this->voidReason);
            session()->flash('success', "Factura {$sale->number} anulada.");
        } catch (SalesException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->cancelVoid();
    }

    public function delete(int $id, SaleService $sales): void
    {
        $sale = Sale::query()->findOrFail($id);
        $this->authorize('delete', $sale);

        try {
            $sales->deleteDraft($sale);
            session()->flash('success', 'Borrador eliminado.');
        } catch (SalesException $e) {
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
        $this->authorize('viewAny', Sale::class);

        $query = Sale::query()
            ->with(['customer:id,code,name', 'branch:id,name', 'receivable'])
            ->when($this->search !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('number', 'like', '%'.$this->search.'%')
                    ->orWhere('reference', 'like', '%'.$this->search.'%')
                    ->orWhereHas('customer', fn ($c) => $c->search($this->search))
            ))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->from !== '', fn ($q) => $q->where('date', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->where('date', '<=', $this->to));

        $issuedTotal = (clone $query)->where('status', SaleStatus::Issued)->sum('total');

        return view('livewire.sales.sale-index', [
            'sales' => $query->orderByDesc('date')->orderByDesc('id')->paginate(25),
            'statuses' => SaleStatus::cases(),
            'issuedTotal' => Money::of((string) $issuedTotal),
        ]);
    }
}
