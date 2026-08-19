<?php

declare(strict_types=1);

namespace App\Livewire\Purchases;

use App\Domains\Partners\Models\Supplier;
use App\Domains\Payables\Services\PayableService;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Estado de cuenta del proveedor')]
class SupplierStatement extends Component
{
    #[Url(as: 'proveedor', except: '')]
    public ?int $supplierId = null;

    #[Url(as: 'desde')]
    public string $from = '';

    #[Url(as: 'hasta')]
    public string $to = '';

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfYear()->toDateString();
        $this->to = $this->to ?: now()->endOfMonth()->toDateString();
    }

    public function render(PayableService $payables): View
    {
        $this->authorize('payables.reports');

        $supplier = $this->supplierId === null
            ? null
            : Supplier::query()->find($this->supplierId);

        return view('livewire.purchases.supplier-statement', [
            'supplier' => $supplier,
            'statement' => $supplier === null
                ? null
                : $payables->statement($supplier, $this->from, $this->to),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }
}
