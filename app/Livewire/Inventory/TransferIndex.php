<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domains\Inventory\Enums\StockDocumentStatus;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Inventory\Models\StockTransfer;
use App\Domains\Inventory\Services\StockTransferService;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Traslados entre bodegas')]
class TransferIndex extends Component
{
    use WithPagination;

    #[Url(as: 'estado', except: '')]
    public string $statusFilter = '';

    public ?int $voidingId = null;

    public string $voidReason = '';

    public function updated(string $property): void
    {
        if ($property === 'statusFilter') {
            $this->resetPage();
        }
    }

    public function post(int $id, StockTransferService $transfers): void
    {
        $transfer = StockTransfer::query()->findOrFail($id);
        $this->authorize('post', $transfer);

        try {
            $posted = $transfers->post($transfer);
            session()->flash('success', "Traslado {$posted->number} aplicado.");
        } catch (InventoryException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function delete(int $id, StockTransferService $transfers): void
    {
        $transfer = StockTransfer::query()->findOrFail($id);
        $this->authorize('delete', $transfer);

        try {
            $transfers->deleteDraft($transfer);
            session()->flash('success', 'Borrador eliminado.');
        } catch (InventoryException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function confirmVoid(int $id): void
    {
        $transfer = StockTransfer::query()->findOrFail($id);
        $this->authorize('void', $transfer);

        $this->voidingId = $id;
        $this->voidReason = '';
    }

    public function void(StockTransferService $transfers): void
    {
        $this->validate([
            'voidReason' => ['required', 'string', 'min:5', 'max:500'],
        ], attributes: ['voidReason' => 'motivo']);

        $transfer = StockTransfer::query()->findOrFail($this->voidingId);
        $this->authorize('void', $transfer);

        try {
            $transfers->void($transfer, $this->voidReason);
            session()->flash('success', "Traslado {$transfer->number} anulado.");
        } catch (InventoryException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->cancelVoid();
    }

    public function cancelVoid(): void
    {
        $this->reset(['voidingId', 'voidReason']);
        $this->resetValidation();
    }

    public function render(): View
    {
        $this->authorize('viewAny', StockTransfer::class);

        return view('livewire.inventory.transfer-index', [
            'transfers' => StockTransfer::query()
                ->with(['fromWarehouse:id,code', 'toWarehouse:id,code'])
                ->withCount('items')
                ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
                ->orderByDesc('date')->orderByDesc('id')
                ->paginate(25),
            'statuses' => StockDocumentStatus::cases(),
        ]);
    }
}
