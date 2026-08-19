<?php

declare(strict_types=1);

namespace App\Livewire\Treasury;

use App\Domains\Treasury\Enums\CheckStatus;
use App\Domains\Treasury\Exceptions\TreasuryException;
use App\Domains\Treasury\Models\BankAccount;
use App\Domains\Treasury\Models\Check;
use App\Domains\Treasury\Services\CheckService;
use App\Support\Money;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Cheques')]
class CheckIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'estado', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'cuenta', except: '')]
    public string $bankAccountId = '';

    public ?int $clearingId = null;

    public string $clearedOn = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'bankAccountId'], strict: true)) {
            $this->resetPage();
        }
    }

    public function markDelivered(int $id, CheckService $checks): void
    {
        $check = Check::query()->findOrFail($id);
        $this->authorize('update', $check);

        try {
            $checks->markDelivered($check);
            session()->flash('success', $check->label().' marcado como entregado.');
        } catch (TreasuryException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function confirmClear(int $id): void
    {
        $check = Check::query()->findOrFail($id);
        $this->authorize('update', $check);

        $this->clearingId = $id;
        $this->clearedOn = now()->toDateString();
    }

    public function markCleared(CheckService $checks): void
    {
        $this->validate([
            'clearedOn' => ['required', 'date'],
        ], attributes: ['clearedOn' => 'fecha de cobro']);

        $check = Check::query()->findOrFail($this->clearingId);
        $this->authorize('update', $check);

        try {
            $checks->markCleared($check, $this->clearedOn);
            session()->flash('success', $check->label().' marcado como cobrado.');
        } catch (TreasuryException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->cancelClear();
    }

    public function cancelClear(): void
    {
        $this->reset(['clearingId', 'clearedOn']);
        $this->resetValidation();
    }

    public function render(): View
    {
        $this->authorize('viewAny', Check::class);

        $query = Check::query()
            ->with('bankAccount:id,bank_name,number,alias')
            ->when($this->search !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('number', 'like', '%'.$this->search.'%')
                    ->orWhere('payee', 'like', '%'.$this->search.'%')
            ))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->bankAccountId !== '', fn ($q) => $q->where('bank_account_id', $this->bankAccountId));

        $outstanding = (clone $query)
            ->whereIn('status', [CheckStatus::Issued, CheckStatus::Delivered])
            ->sum('amount');

        return view('livewire.treasury.check-index', [
            'checks' => $query->orderByDesc('date')->orderByDesc('id')->paginate(25),
            'statuses' => CheckStatus::cases(),
            'bankAccounts' => BankAccount::query()->orderBy('bank_name')->get(),
            'outstandingTotal' => Money::of((string) $outstanding),
        ]);
    }
}
