<?php

declare(strict_types=1);

namespace App\Livewire\Treasury;

use App\Domains\Accounting\Models\Account;
use App\Domains\Treasury\Exceptions\TreasuryException;
use App\Domains\Treasury\Models\BankAccount;
use App\Domains\Treasury\Services\BankAccountService;
use App\Support\Money;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Cuentas bancarias')]
class BankAccountIndex extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public ?int $account_id = null;

    public string $bank_name = '';

    public string $number = '';

    public string $alias = '';

    public string $type = 'checking';

    public string $next_check_number = '';

    public bool $is_active = true;

    public string $notes = '';

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'account_id' => ['required', 'integer'],
            'bank_name' => ['required', 'string', 'max:120'],
            'number' => ['required', 'string', 'max:40'],
            'alias' => ['nullable', 'string', 'max:80'],
            'type' => ['required', 'in:checking,savings'],
            'next_check_number' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'account_id.required' => 'Elige la cuenta contable donde vive el dinero.',
            'bank_name.required' => 'Indica el banco.',
            'number.required' => 'Indica el número de cuenta.',
        ];
    }

    public function create(): void
    {
        $this->authorize('create', BankAccount::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $bankAccount = BankAccount::query()->findOrFail($id);
        $this->authorize('update', $bankAccount);

        $this->editingId = $bankAccount->id;
        $this->account_id = $bankAccount->account_id;
        $this->bank_name = $bankAccount->bank_name;
        $this->number = $bankAccount->number;
        $this->alias = (string) $bankAccount->alias;
        $this->type = $bankAccount->type;
        $this->next_check_number = (string) ($bankAccount->next_check_number ?? '');
        $this->is_active = $bankAccount->is_active;
        $this->notes = (string) $bankAccount->notes;
        $this->showForm = true;
    }

    public function save(BankAccountService $banks): void
    {
        $data = $this->validate();
        $data['is_active'] = $this->is_active;

        try {
            if ($this->editingId === null) {
                $this->authorize('create', BankAccount::class);
                $banks->create($data);
                session()->flash('success', 'Cuenta bancaria registrada.');
            } else {
                $bankAccount = BankAccount::query()->findOrFail($this->editingId);
                $this->authorize('update', $bankAccount);
                $banks->update($bankAccount, $data);
                session()->flash('success', 'Cuenta bancaria actualizada.');
            }

            $this->closeForm();
        } catch (TreasuryException $e) {
            $this->addError('account_id', $e->getMessage());
        }
    }

    public function delete(int $id, BankAccountService $banks): void
    {
        $bankAccount = BankAccount::query()->findOrFail($id);
        $this->authorize('delete', $bankAccount);

        $banks->delete($bankAccount);
        session()->flash('success', 'Cuenta bancaria eliminada.');
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
        $this->resetValidation();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'account_id', 'bank_name', 'number',
            'alias', 'type', 'next_check_number', 'notes',
        ]);
        $this->is_active = true;
    }

    public function render(BankAccountService $banks): View
    {
        $this->authorize('viewAny', BankAccount::class);

        $accounts = BankAccount::query()
            ->with('account:id,code,name')
            ->orderBy('bank_name')
            ->get()
            ->map(function (BankAccount $bankAccount) use ($banks): BankAccount {
                $bankAccount->setAttribute('book_balance', $banks->bookBalance($bankAccount));

                return $bankAccount;
            });

        return view('livewire.treasury.bank-account-index', [
            'accounts' => $accounts,
            'total' => Money::sum($accounts->map(fn ($a) => $a->getAttribute('book_balance'))->all()),
            'cashAccounts' => Account::query()
                ->where('is_cash_equivalent', true)
                ->where('is_postable', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ]);
    }
}
