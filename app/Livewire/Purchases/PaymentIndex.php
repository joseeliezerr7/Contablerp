<?php

declare(strict_types=1);

namespace App\Livewire\Purchases;

use App\Domains\Accounting\Models\Account;
use App\Domains\Partners\Models\Supplier;
use App\Domains\Payables\Exceptions\PayableException;
use App\Domains\Payables\Models\Payable;
use App\Domains\Payables\Models\Payment;
use App\Domains\Payables\Services\PaymentService;
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

#[Title('Pagos a proveedores')]
class PaymentIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $branch_id = null;

    public ?int $supplier_id = null;

    public string $date = '';

    public string $payment_method = 'transfer';

    public string $reference = '';

    public ?int $payment_account_id = null;

    public string $notes = '';

    /**
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
        $this->payment_account_id = Account::query()->cash()->orderBy('code')->value('id');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSupplierId(): void
    {
        $this->applications = [];
    }

    public function create(): void
    {
        $this->authorize('create', Payment::class);

        $this->resetForm();
        $this->showForm = true;
    }

    /**
     * Reparte el importe entre los documentos más próximos a vencer, que es el
     * criterio con el que se programa un pago a proveedores.
     */
    public function applyOldestFirst(string $amount): void
    {
        $remaining = is_numeric($amount) ? Money::of($amount) : Money::zero();
        $this->applications = [];

        foreach ($this->openPayables() as $payable) {
            if (! $remaining->isPositive()) {
                break;
            }

            $applied = $remaining->greaterThan($payable->balanceAmount())
                ? $payable->balanceAmount()
                : $remaining;

            $this->applications[$payable->id] = $applied->round(2)->toString();
            $remaining = $remaining->minus($applied);
        }
    }

    /**
     * @return Collection<int, Payable>
     */
    #[Computed]
    public function openPayables(): Collection
    {
        if ($this->supplier_id === null) {
            return collect();
        }

        return Payable::query()
            ->where('supplier_id', $this->supplier_id)
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

    public function save(PaymentService $payments): void
    {
        $this->validate([
            'branch_id' => ['required', 'integer'],
            'supplier_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'payment_method' => ['required', 'in:'.implode(',', PaymentMethod::values())],
            'payment_account_id' => ['required', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
        ], attributes: [
            'supplier_id' => 'proveedor',
            'payment_account_id' => 'cuenta de pago',
        ]);

        $applications = [];

        foreach ($this->applications as $payableId => $amount) {
            if (! is_numeric($amount) || Money::of((string) $amount)->isZero()) {
                continue;
            }

            $applications[] = ['payable_id' => (int) $payableId, 'amount' => (string) $amount];
        }

        try {
            $payment = $payments->create([
                'branch_id' => $this->branch_id,
                'supplier_id' => $this->supplier_id,
                'date' => $this->date,
                'payment_method' => $this->payment_method,
                'reference' => $this->reference ?: null,
                'payment_account_id' => $this->payment_account_id,
                'notes' => $this->notes ?: null,
            ], $applications);

            session()->flash('success', "Pago {$payment->number} registrado por {$payment->amountMoney()->format()}.");

            $this->resetForm();
            $this->showForm = false;
        } catch (PayableException $e) {
            $this->addError('applications', $e->getMessage());
        }
    }

    public function confirmVoid(int $id): void
    {
        $payment = Payment::query()->findOrFail($id);
        $this->authorize('void', $payment);

        $this->voidingId = $id;
        $this->voidReason = '';
    }

    public function void(PaymentService $payments): void
    {
        $this->validate([
            'voidReason' => ['required', 'string', 'min:5', 'max:500'],
        ], attributes: ['voidReason' => 'motivo']);

        $payment = Payment::query()->findOrFail($this->voidingId);
        $this->authorize('void', $payment);

        try {
            $payments->void($payment, $this->voidReason);
            session()->flash('success', "Pago {$payment->number} anulado.");
        } catch (PayableException $e) {
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
        $this->authorize('viewAny', Payment::class);

        return view('livewire.purchases.payment-index', [
            'payments' => Payment::query()
                ->with('supplier:id,code,name')
                ->when($this->search !== '', fn ($q) => $q->where(
                    fn ($w) => $w->where('number', 'like', '%'.$this->search.'%')
                        ->orWhere('reference', 'like', '%'.$this->search.'%')
                        ->orWhereHas('supplier', fn ($s) => $s->search($this->search))
                ))
                ->orderByDesc('date')->orderByDesc('id')
                ->paginate(25),
            'suppliers' => Supplier::query()->active()->orderBy('name')->get(),
            'branches' => Branch::query()->active()->orderBy('code')->get(),
            'cashAccounts' => Account::query()->cash()->orderBy('code')->get(),
            'methods' => PaymentMethod::cases(),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset(['supplier_id', 'reference', 'notes', 'applications']);
        $this->date = now()->toDateString();
        $this->payment_method = 'transfer';
        $this->resetValidation();
    }
}
