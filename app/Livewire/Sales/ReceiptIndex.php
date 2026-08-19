<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Domains\Accounting\Models\Account;
use App\Domains\Partners\Models\Customer;
use App\Domains\Receivables\Exceptions\ReceivableException;
use App\Domains\Receivables\Models\Receipt;
use App\Domains\Receivables\Models\Receivable;
use App\Domains\Receivables\Services\ReceiptService;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Tenancy\Models\Branch;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Recibos de cobro')]
class ReceiptIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $branch_id = null;

    public ?int $customer_id = null;

    public string $date = '';

    public string $payment_method = 'cash';

    public string $reference = '';

    public ?int $deposit_account_id = null;

    public string $notes = '';

    /**
     * Importe a aplicar por documento: [receivable_id => importe].
     *
     * @var array<int, string>
     */
    public array $applications = [];

    public ?int $voidingId = null;

    public string $voidReason = '';

    public function mount(): void
    {
        $this->date = now()->toDateString();
        $this->branch_id = app(CompanyContext::class)->branchId()
            ?? Branch::query()->where('is_main', true)->value('id');
        $this->deposit_account_id = Account::query()->cash()->orderBy('code')->value('id');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Al cambiar de cliente se descartan las aplicaciones anteriores: son de
     * documentos que ya no corresponden.
     */
    public function updatedCustomerId(): void
    {
        $this->applications = [];
    }

    public function create(): void
    {
        $this->authorize('create', Receipt::class);

        $this->resetForm();
        $this->showForm = true;
    }

    /**
     * Reparte el importe recibido entre los documentos más antiguos, que es lo
     * que hace un cobrador cuando el cliente abona una cantidad global.
     */
    public function applyOldestFirst(string $amount): void
    {
        $remaining = is_numeric($amount) ? Money::of($amount) : Money::zero();
        $this->applications = [];

        foreach ($this->openReceivables() as $receivable) {
            if (! $remaining->isPositive()) {
                break;
            }

            $applied = $remaining->greaterThan($receivable->balanceAmount())
                ? $receivable->balanceAmount()
                : $remaining;

            $this->applications[$receivable->id] = $applied->round(2)->toString();
            $remaining = $remaining->minus($applied);
        }
    }

    /**
     * @return Collection<int, Receivable>
     */
    #[Computed]
    public function openReceivables(): Collection
    {
        if ($this->customer_id === null) {
            return collect();
        }

        return Receivable::query()
            ->where('customer_id', $this->customer_id)
            ->outstanding()
            ->orderBy('due_date')
            ->get();
    }

    #[Computed]
    public function appliedTotal(): Money
    {
        return Money::sum(array_map(
            fn ($amount) => is_numeric($amount) ? Money::of((string) $amount) : Money::zero(),
            $this->applications,
        ));
    }

    public function save(ReceiptService $receipts): void
    {
        $this->validate([
            'branch_id' => ['required', 'integer'],
            'customer_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'payment_method' => ['required', 'in:'.implode(',', PaymentMethod::values())],
            'deposit_account_id' => ['required', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
        ], attributes: [
            'customer_id' => 'cliente',
            'deposit_account_id' => 'cuenta de depósito',
        ]);

        $applications = [];

        foreach ($this->applications as $receivableId => $amount) {
            if (! is_numeric($amount) || Money::of((string) $amount)->isZero()) {
                continue;
            }

            $applications[] = ['receivable_id' => (int) $receivableId, 'amount' => (string) $amount];
        }

        try {
            $receipt = $receipts->create([
                'branch_id' => $this->branch_id,
                'customer_id' => $this->customer_id,
                'date' => $this->date,
                'payment_method' => $this->payment_method,
                'reference' => $this->reference ?: null,
                'deposit_account_id' => $this->deposit_account_id,
                'notes' => $this->notes ?: null,
            ], $applications);

            session()->flash('success', "Recibo {$receipt->number} registrado por {$receipt->amountMoney()->format()}.");

            $this->resetForm();
            $this->showForm = false;
        } catch (ReceivableException $e) {
            $this->addError('applications', $e->getMessage());
        }
    }

    public function confirmVoid(int $id): void
    {
        $receipt = Receipt::query()->findOrFail($id);
        $this->authorize('void', $receipt);

        $this->voidingId = $id;
        $this->voidReason = '';
    }

    public function void(ReceiptService $receipts): void
    {
        $this->validate([
            'voidReason' => ['required', 'string', 'min:5', 'max:500'],
        ], attributes: ['voidReason' => 'motivo']);

        $receipt = Receipt::query()->findOrFail($this->voidingId);
        $this->authorize('void', $receipt);

        try {
            $receipts->void($receipt, $this->voidReason);
            session()->flash('success', "Recibo {$receipt->number} anulado.");
        } catch (ReceivableException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->cancelVoid();
    }

    public function cancelVoid(): void
    {
        $this->reset(['voidingId', 'voidReason']);
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function render(): View
    {
        $this->authorize('viewAny', Receipt::class);

        return view('livewire.sales.receipt-index', [
            'receipts' => Receipt::query()
                ->with('customer:id,code,name')
                ->when($this->search !== '', fn ($q) => $q->where(
                    fn ($w) => $w->where('number', 'like', '%'.$this->search.'%')
                        ->orWhere('reference', 'like', '%'.$this->search.'%')
                        ->orWhereHas('customer', fn ($c) => $c->search($this->search))
                ))
                ->orderByDesc('date')->orderByDesc('id')
                ->paginate(25),
            'customers' => Customer::query()->active()->orderBy('name')->get(),
            'branches' => Branch::query()->active()->orderBy('code')->get(),
            'cashAccounts' => Account::query()->cash()->orderBy('code')->get(),
            'methods' => PaymentMethod::cases(),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset(['customer_id', 'reference', 'notes', 'applications']);
        $this->date = now()->toDateString();
        $this->payment_method = 'cash';
        $this->resetValidation();
    }
}
