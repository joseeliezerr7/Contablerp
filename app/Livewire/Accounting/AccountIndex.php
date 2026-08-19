<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Domains\Accounting\Enums\AccountNature;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\CashFlowClass;
use App\Domains\Accounting\Exceptions\AccountingException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Services\ChartOfAccountsService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Plan de cuentas')]
class AccountIndex extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'tipo', except: '')]
    public string $typeFilter = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public ?int $parent_id = null;

    public string $code = '';

    public string $name = '';

    public string $type = '';

    public string $nature = '';

    public string $cash_flow_class = '';

    public bool $requires_partner = false;

    public bool $requires_branch = false;

    public bool $is_active = true;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('accounts', 'code')
                    ->where('company_id', app(CompanyContext::class)->idOrFail())
                    ->ignore($this->editingId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'nature' => ['required', Rule::enum(AccountNature::class)],
            'cash_flow_class' => ['nullable', Rule::enum(CashFlowClass::class)],
            'parent_id' => ['nullable', 'integer'],
            'requires_partner' => ['boolean'],
            'requires_branch' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'code' => 'código',
            'name' => 'nombre',
            'type' => 'tipo',
            'nature' => 'naturaleza',
            'parent_id' => 'cuenta padre',
        ];
    }

    /**
     * Al elegir el tipo, la naturaleza se propone sola. El contador puede
     * cambiarla para las contra-cuentas (depreciación acumulada, devoluciones).
     */
    public function updatedType(string $value): void
    {
        if ($value !== '') {
            $this->nature = AccountType::from($value)->nature()->value;
        }
    }

    public function create(?int $parentId = null): void
    {
        $this->authorize('create', Account::class);

        $this->resetForm();

        if ($parentId !== null) {
            $parent = Account::query()->findOrFail($parentId);
            $this->parent_id = $parent->id;
            $this->code = $parent->code.'.';
            $this->type = $parent->type->value;
            $this->nature = $parent->nature->value;
            $this->cash_flow_class = $parent->cash_flow_class?->value ?? '';
        }

        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $account = Account::query()->findOrFail($id);
        $this->authorize('update', $account);

        $this->editingId = $account->id;
        $this->parent_id = $account->parent_id;
        $this->code = $account->code;
        $this->name = $account->name;
        $this->type = $account->type->value;
        $this->nature = $account->nature->value;
        $this->cash_flow_class = $account->cash_flow_class?->value ?? '';
        $this->requires_partner = $account->requires_partner;
        $this->requires_branch = $account->requires_branch;
        $this->is_active = $account->is_active;
        $this->showForm = true;
    }

    public function save(ChartOfAccountsService $service): void
    {
        $data = $this->validate();
        $data['cash_flow_class'] = $data['cash_flow_class'] ?: null;

        try {
            if ($this->editingId !== null) {
                $account = Account::query()->findOrFail($this->editingId);
                $this->authorize('update', $account);
                $service->update($account, $data);
                session()->flash('success', 'Cuenta actualizada.');
            } else {
                $this->authorize('create', Account::class);
                $service->create($data);
                session()->flash('success', 'Cuenta creada.');
            }
        } catch (AccountingException $e) {
            $this->addError('code', $e->getMessage());

            return;
        }

        $this->resetForm();
        $this->showForm = false;
    }

    public function delete(int $id, ChartOfAccountsService $service): void
    {
        $account = Account::query()->findOrFail($id);
        $this->authorize('delete', $account);

        try {
            $service->delete($account);
            session()->flash('success', 'Cuenta eliminada.');
        } catch (AccountingException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function render(): View
    {
        $this->authorize('viewAny', Account::class);

        $accounts = Account::query()
            ->when($this->search !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('code', 'like', $this->search.'%')
                    ->orWhere('name', 'like', '%'.$this->search.'%')
            ))
            ->when($this->typeFilter !== '', fn ($query) => $query->where('type', $this->typeFilter))
            ->orderBy('code')
            ->get();

        return view('livewire.accounting.account-index', [
            'accounts' => $accounts,
            'types' => AccountType::cases(),
            'natures' => AccountNature::cases(),
            'cashFlowClasses' => CashFlowClass::cases(),
            'parents' => Account::query()->orderBy('code')->get(),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'parent_id', 'code', 'name', 'type', 'nature',
            'cash_flow_class', 'requires_partner', 'requires_branch',
        ]);
        $this->is_active = true;
        $this->resetValidation();
    }
}
