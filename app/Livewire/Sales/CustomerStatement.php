<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Domains\Partners\Models\Customer;
use App\Domains\Receivables\Services\ReceivableService;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Estado de cuenta')]
class CustomerStatement extends Component
{
    #[Url(as: 'cliente', except: '')]
    public ?int $customerId = null;

    #[Url(as: 'desde')]
    public string $from = '';

    #[Url(as: 'hasta')]
    public string $to = '';

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfYear()->toDateString();
        $this->to = $this->to ?: now()->endOfMonth()->toDateString();
    }

    public function render(ReceivableService $receivables): View
    {
        $this->authorize('receivables.reports');

        $customer = $this->customerId === null
            ? null
            : Customer::query()->find($this->customerId);

        return view('livewire.sales.customer-statement', [
            'customer' => $customer,
            'statement' => $customer === null
                ? null
                : $receivables->statement($customer, $this->from, $this->to),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }
}
