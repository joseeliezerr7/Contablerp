<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domains\Inventory\Enums\StockDocumentStatus;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Inventory\Models\StockAdjustment;
use App\Domains\Inventory\Services\StockAdjustmentService;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Ajustes de inventario')]
class AdjustmentIndex extends Component
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

    public function post(int $id, StockAdjustmentService $adjustments): void
    {
        $adjustment = StockAdjustment::query()->findOrFail($id);
        $this->authorize('post', $adjustment);

        try {
            $posted = $adjustments->post($adjustment);
            session()->flash('success', "Ajuste {$posted->number} aplicado.");
        } catch (InventoryException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function delete(int $id, StockAdjustmentService $adjustments): void
    {
        $adjustment = StockAdjustment::query()->findOrFail($id);
        $this->authorize('delete', $adjustment);

        try {
            $adjustments->deleteDraft($adjustment);
            session()->flash('success', 'Borrador eliminado.');
        } catch (InventoryException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function confirmVoid(int $id): void
    {
        $adjustment = StockAdjustment::query()->findOrFail($id);
        $this->authorize('void', $adjustment);

        $this->voidingId = $id;
        $this->voidReason = '';
    }

    public function void(StockAdjustmentService $adjustments): void
    {
        $this->validate([
            'voidReason' => ['required', 'string', 'min:5', 'max:500'],
        ], attributes: ['voidReason' => 'motivo']);

        $adjustment = StockAdjustment::query()->findOrFail($this->voidingId);
        $this->authorize('void', $adjustment);

        try {
            $adjustments->void($adjustment, $this->voidReason);
            session()->flash('success', "Ajuste {$adjustment->number} anulado.");
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
        $this->authorize('viewAny', StockAdjustment::class);

        return view('livewire.inventory.adjustment-index', [
            'adjustments' => StockAdjustment::query()
                ->with(['warehouse:id,code,name'])
                ->withCount('items')
                ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
                ->orderByDesc('date')->orderByDesc('id')
                ->paginate(25),
            'statuses' => StockDocumentStatus::cases(),
        ]);
    }
}
