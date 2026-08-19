<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Exceptions\AccountingException;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\AccountingEngine;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Libro diario')]
class JournalIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'estado', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'desde', except: '')]
    public string $from = '';

    #[Url(as: 'hasta', except: '')]
    public string $to = '';

    /** Partida sobre la que se pide motivo de anulación o reversión. */
    public ?int $actionEntryId = null;

    public string $actionType = '';

    public string $actionReason = '';

    public string $actionDate = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'from', 'to'], strict: true)) {
            $this->resetPage();
        }
    }

    public function post(int $id, AccountingEngine $engine): void
    {
        $entry = JournalEntry::query()->findOrFail($id);
        $this->authorize('post', $entry);

        try {
            $posted = $engine->postEntry($entry);
            session()->flash('success', "Partida {$posted->number} contabilizada.");
        } catch (AccountingException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function confirmVoid(int $id): void
    {
        $entry = JournalEntry::query()->findOrFail($id);
        $this->authorize('void', $entry);

        $this->actionEntryId = $id;
        $this->actionType = 'void';
        $this->actionReason = '';
    }

    public function confirmReverse(int $id): void
    {
        $entry = JournalEntry::query()->findOrFail($id);
        $this->authorize('reverse', $entry);

        $this->actionEntryId = $id;
        $this->actionType = 'reverse';
        $this->actionReason = '';
        $this->actionDate = now()->toDateString();
    }

    public function runAction(AccountingEngine $engine): void
    {
        $this->validate([
            'actionReason' => ['required', 'string', 'min:5', 'max:500'],
            'actionDate' => [$this->actionType === 'reverse' ? 'required' : 'nullable', 'date'],
        ], attributes: ['actionReason' => 'motivo', 'actionDate' => 'fecha']);

        $entry = JournalEntry::query()->findOrFail($this->actionEntryId);

        try {
            if ($this->actionType === 'void') {
                $this->authorize('void', $entry);
                $engine->void($entry, $this->actionReason);
                session()->flash('success', "Partida {$entry->number} anulada.");
            } else {
                $this->authorize('reverse', $entry);
                $reversal = $engine->reverse($entry, $this->actionReason, $this->actionDate);
                session()->flash('success', "Reversión {$reversal->number} generada.");
            }
        } catch (AccountingException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->cancelAction();
    }

    public function delete(int $id, AccountingEngine $engine): void
    {
        $entry = JournalEntry::query()->findOrFail($id);
        $this->authorize('delete', $entry);

        try {
            $engine->deleteDraft($entry);
            session()->flash('success', 'Borrador eliminado.');
        } catch (AccountingException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function cancelAction(): void
    {
        $this->reset(['actionEntryId', 'actionType', 'actionReason', 'actionDate']);
        $this->resetValidation();
    }

    public function render(): View
    {
        $this->authorize('viewAny', JournalEntry::class);

        $entries = JournalEntry::query()
            ->with(['period:id,name', 'branch:id,name'])
            ->when($this->search !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('number', 'like', '%'.$this->search.'%')
                    ->orWhere('concept', 'like', '%'.$this->search.'%')
                    ->orWhere('reference', 'like', '%'.$this->search.'%')
            ))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->from !== '', fn ($query) => $query->where('date', '>=', $this->from))
            ->when($this->to !== '', fn ($query) => $query->where('date', '<=', $this->to))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.accounting.journal-index', [
            'entries' => $entries,
            'statuses' => JournalEntryStatus::cases(),
        ]);
    }
}
