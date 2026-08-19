<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Tenancy\Models\Branch;
use App\Support\Money;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Libro mayor')]
class LedgerView extends Component
{
    #[Url(as: 'cuenta', except: '')]
    public string $accountCode = '';

    #[Url(as: 'desde')]
    public string $from = '';

    #[Url(as: 'hasta')]
    public string $to = '';

    #[Url(as: 'sucursal', except: '')]
    public ?int $branchId = null;

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfYear()->toDateString();
        $this->to = $this->to ?: now()->endOfMonth()->toDateString();
    }

    public function render(LedgerQueryService $ledger): View
    {
        $this->authorize('accounting.ledger.view');

        $account = $this->accountCode === ''
            ? null
            : Account::query()->where('code', $this->accountCode)->first();

        $result = null;

        if ($account !== null && $account->is_postable) {
            $result = $ledger->ledgerFor($account, $this->from, $this->to, $this->branchId);
        }

        return view('livewire.accounting.ledger-view', [
            'account' => $account,
            'result' => $result,
            'accounts' => Account::query()->postable()->orderBy('code')->get(),
            'branches' => Branch::query()->active()->orderBy('code')->get(),
            'zero' => Money::zero(),
        ]);
    }
}
