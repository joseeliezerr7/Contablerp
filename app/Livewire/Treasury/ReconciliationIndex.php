<?php

declare(strict_types=1);

namespace App\Livewire\Treasury;

use App\Domains\Treasury\Exceptions\TreasuryException;
use App\Domains\Treasury\Models\BankAccount;
use App\Domains\Treasury\Models\BankReconciliation;
use App\Domains\Treasury\Services\BankReconciliationService;
use App\Support\Money;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Conciliación bancaria')]
class ReconciliationIndex extends Component
{
    #[Url(as: 'cuenta', except: '')]
    public string $bankAccountId = '';

    public bool $showForm = false;

    public string $cutoff_date = '';

    public string $statement_balance = '';

    public string $notes = '';

    public function mount(): void
    {
        if ($this->bankAccountId === '') {
            $this->bankAccountId = (string) (BankAccount::query()->active()->value('id') ?? '');
        }

        $this->cutoff_date = now()->endOfMonth()->toDateString();
    }

    public function create(): void
    {
        $this->authorize('create', BankReconciliation::class);
        $this->showForm = true;
    }

    public function save(BankReconciliationService $reconciliations): void
    {
        $this->authorize('create', BankReconciliation::class);

        $data = $this->validate([
            'bankAccountId' => ['required', 'integer'],
            'cutoff_date' => ['required', 'date'],
            'statement_balance' => ['required', 'numeric'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'bankAccountId' => 'cuenta bancaria',
            'cutoff_date' => 'fecha de corte',
            'statement_balance' => 'saldo del extracto',
        ]);

        $bankAccount = BankAccount::query()->findOrFail($data['bankAccountId']);

        $reconciliation = $reconciliations->open(
            $bankAccount,
            $data['cutoff_date'],
            Money::of((string) $data['statement_balance']),
            $data['notes'] ?? null,
        );

        $this->showForm = false;
        $this->reset(['statement_balance', 'notes']);

        $this->redirectRoute('treasury.reconciliations.show', $reconciliation, navigate: true);
    }

    public function delete(int $id, BankReconciliationService $reconciliations): void
    {
        $reconciliation = BankReconciliation::query()->findOrFail($id);
        $this->authorize('delete', $reconciliation);

        try {
            $reconciliations->delete($reconciliation);
            session()->flash('success', 'Conciliación eliminada.');
        } catch (TreasuryException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $this->authorize('viewAny', BankReconciliation::class);

        return view('livewire.treasury.reconciliation-index', [
            'reconciliations' => BankReconciliation::query()
                ->with('bankAccount:id,bank_name,number,alias')
                ->when($this->bankAccountId !== '', fn ($q) => $q->where('bank_account_id', $this->bankAccountId))
                ->orderByDesc('cutoff_date')
                ->orderByDesc('id')
                ->paginate(25),
            'bankAccounts' => BankAccount::query()->active()->orderBy('bank_name')->get(),
        ]);
    }
}
