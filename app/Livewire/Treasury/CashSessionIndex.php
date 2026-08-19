<?php

declare(strict_types=1);

namespace App\Livewire\Treasury;

use App\Domains\Accounting\Models\Account;
use App\Domains\Tenancy\Models\Branch;
use App\Domains\Treasury\Exceptions\TreasuryException;
use App\Domains\Treasury\Models\CashSession;
use App\Domains\Treasury\Services\CashSessionService;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Caja')]
class CashSessionIndex extends Component
{
    use WithPagination;

    public bool $showOpen = false;

    public ?int $closingId = null;

    public ?int $branch_id = null;

    public ?int $account_id = null;

    public string $opening_float = '0.00';

    public string $counted_amount = '';

    public string $notes = '';

    public function mount(CompanyContext $context): void
    {
        $this->branch_id = $context->branchId() ?? Branch::query()->where('is_main', true)->value('id');
        $this->account_id = Account::query()
            ->where('is_cash_equivalent', true)
            ->where('is_postable', true)
            ->orderBy('code')
            ->value('id');
    }

    public function openTill(): void
    {
        $this->authorize('create', CashSession::class);
        $this->showOpen = true;
    }

    public function save(CashSessionService $sessions): void
    {
        $this->authorize('create', CashSession::class);

        $data = $this->validate([
            'branch_id' => ['required', 'integer'],
            'account_id' => ['required', 'integer'],
            'opening_float' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'branch_id' => 'sucursal',
            'account_id' => 'caja',
            'opening_float' => 'fondo inicial',
        ]);

        try {
            $session = $sessions->open($data);
            session()->flash('success', "Caja {$session->number} abierta.");
            $this->showOpen = false;
            $this->reset(['opening_float', 'notes']);
            $this->opening_float = '0.00';
        } catch (TreasuryException $e) {
            $this->addError('account_id', $e->getMessage());
        }
    }

    public function confirmClose(int $id): void
    {
        $session = CashSession::query()->findOrFail($id);
        $this->authorize('close', $session);

        $this->closingId = $id;
        $this->counted_amount = '';
        $this->notes = '';
    }

    public function close(CashSessionService $sessions): void
    {
        $this->validate([
            'counted_amount' => ['required', 'numeric', 'min:0'],
        ], attributes: ['counted_amount' => 'efectivo contado']);

        $session = CashSession::query()->findOrFail($this->closingId);
        $this->authorize('close', $session);

        try {
            $closed = $sessions->close($session, Money::of($this->counted_amount), $this->notes ?: null);

            $message = $closed->differenceAmount()->isZero()
                ? "Caja {$closed->number} cerrada y cuadrada."
                : "Caja {$closed->number} cerrada con una diferencia de {$closed->differenceAmount()->format()}, contabilizada.";

            session()->flash('success', $message);
        } catch (TreasuryException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->cancelClose();
    }

    public function cancelClose(): void
    {
        $this->reset(['closingId', 'counted_amount', 'notes']);
        $this->resetValidation();
    }

    public function render(CashSessionService $sessions): View
    {
        $this->authorize('viewAny', CashSession::class);

        $open = CashSession::query()
            ->with(['account:id,code,name', 'branch:id,name', 'cashier:id,name'])
            ->where('status', 'open')
            ->get()
            ->map(function (CashSession $session) use ($sessions): CashSession {
                $session->setAttribute('expected_now', $sessions->expectedAmount($session));

                return $session;
            });

        return view('livewire.treasury.cash-session-index', [
            'openSessions' => $open,
            'sessions' => CashSession::query()
                ->with(['account:id,code,name', 'branch:id,name', 'cashier:id,name'])
                ->where('status', 'closed')
                ->orderByDesc('closed_at')
                ->paginate(20),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'cashAccounts' => Account::query()
                ->where('is_cash_equivalent', true)
                ->where('is_postable', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ]);
    }
}
