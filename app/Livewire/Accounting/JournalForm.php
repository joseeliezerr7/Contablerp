<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Enums\JournalEntryType;
use App\Domains\Accounting\Exceptions\AccountingException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Tenancy\Models\Branch;
use App\Support\Money;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Captura de partidas.
 *
 * La cuenta se escribe por código (con autocompletado del navegador) en vez de
 * elegirse en un desplegable: un contador teclea «1.1.03.01» más rápido de lo
 * que encuentra la cuenta en una lista de cien.
 */
#[Title('Partida contable')]
class JournalForm extends Component
{
    public ?int $entryId = null;

    public string $date = '';

    public string $concept = '';

    public string $reference = '';

    public ?int $branch_id = null;

    public string $type = 'standard';

    /**
     * @var array<int, array{account_code: string, description: string, debit: string, credit: string}>
     */
    public array $lines = [];

    public function mount(?int $entry = null): void
    {
        $this->date = now()->toDateString();

        if ($entry !== null) {
            $this->loadEntry($entry);

            return;
        }

        $this->authorize('create', JournalEntry::class);
        $this->lines = [$this->emptyLine(), $this->emptyLine()];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'concept' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer'],
            'type' => ['required', 'in:standard,adjustment'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_code' => ['required', 'string'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'lines.min' => 'Una partida necesita al menos dos líneas.',
            'lines.*.account_code.required' => 'Falta la cuenta en una de las líneas.',
        ];
    }

    public function addLine(): void
    {
        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) <= 2) {
            return;
        }

        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    /**
     * Al escribir en el debe se limpia el haber de esa línea, y viceversa: una
     * línea es cargo o abono, nunca las dos cosas.
     *
     * Se usa el hook genérico y no `updatedLines()`: con propiedades anidadas
     * como `lines.0.debit`, el hook por propiedad no se dispara.
     */
    public function updated(string $property, mixed $value): void
    {
        if (! str_starts_with($property, 'lines.')) {
            return;
        }

        [, $index, $field] = array_pad(explode('.', $property), 3, null);

        if ($value === '' || $value === null) {
            return;
        }

        if ($field === 'debit') {
            $this->lines[(int) $index]['credit'] = '';
        }

        if ($field === 'credit') {
            $this->lines[(int) $index]['debit'] = '';
        }
    }

    #[Computed]
    public function totalDebit(): Money
    {
        return $this->sumColumn('debit');
    }

    #[Computed]
    public function totalCredit(): Money
    {
        return $this->sumColumn('credit');
    }

    #[Computed]
    public function difference(): Money
    {
        return $this->totalDebit()->minus($this->totalCredit());
    }

    #[Computed]
    public function isBalanced(): bool
    {
        return $this->difference()->isZero() && ! $this->totalDebit()->isZero();
    }

    public function saveDraft(AccountingEngine $engine): void
    {
        $this->persist($engine, post: false);
    }

    public function saveAndPost(AccountingEngine $engine): void
    {
        $this->persist($engine, post: true);
    }

    public function render(): View
    {
        return view('livewire.accounting.journal-form', [
            'accounts' => Account::query()->postable()->orderBy('code')->get(),
            'branches' => Branch::query()->active()->orderBy('code')->get(),
            'types' => JournalEntryType::manualTypes(),
        ]);
    }

    private function persist(AccountingEngine $engine, bool $post): void
    {
        $this->validate();

        try {
            $draft = $this->buildDraft();

            if ($this->entryId !== null) {
                $entry = JournalEntry::query()->findOrFail($this->entryId);
                $this->authorize('update', $entry);
                $entry = $engine->updateDraft($entry, $draft);
            } else {
                $this->authorize('create', JournalEntry::class);
                $entry = $engine->saveDraft($draft);
                $this->entryId = $entry->id;
            }

            if ($post) {
                $this->authorize('post', $entry);
                $entry = $engine->postEntry($entry);

                session()->flash('success', "Partida {$entry->number} contabilizada.");
                $this->redirectRoute('journal.index', navigate: true);

                return;
            }

            session()->flash('success', 'Borrador guardado.');
            $this->redirectRoute('journal.index', navigate: true);
        } catch (AccountingException $e) {
            // Los mensajes del dominio ya están redactados para el usuario.
            $this->addError('lines', $e->getMessage());
        }
    }

    private function buildDraft(): JournalDraft
    {
        $accounts = Account::query()
            ->whereIn('code', array_column($this->lines, 'account_code'))
            ->get()
            ->keyBy('code');

        $draft = JournalDraft::on($this->date, $this->concept)
            ->ofType(JournalEntryType::from($this->type))
            ->inBranch($this->branch_id)
            ->withReference($this->reference ?: null);

        foreach ($this->lines as $index => $line) {
            $code = trim($line['account_code']);
            $account = $accounts->get($code);

            if ($account === null) {
                throw new AccountingException(
                    "La cuenta «{$code}» de la línea ".($index + 1).' no existe en el plan de cuentas.'
                );
            }

            $debit = $this->toMoney($line['debit'] ?? '');
            $credit = $this->toMoney($line['credit'] ?? '');

            if ($debit->isZero() && $credit->isZero()) {
                throw new AccountingException(
                    'La línea '.($index + 1).' no tiene importe en el debe ni en el haber.'
                );
            }

            $description = $line['description'] ?: null;

            if ($debit->isPositive()) {
                $draft->debit($account->id, $debit, $description);
            } else {
                $draft->credit($account->id, $credit, $description);
            }
        }

        return $draft;
    }

    private function loadEntry(int $entryId): void
    {
        $entry = JournalEntry::query()->with('lines.account')->findOrFail($entryId);
        $this->authorize('update', $entry);

        $this->entryId = $entry->id;
        $this->date = $entry->date->toDateString();
        $this->concept = $entry->concept;
        $this->reference = (string) $entry->reference;
        $this->branch_id = $entry->branch_id;
        $this->type = $entry->type->value;

        $this->lines = $entry->lines->map(fn ($line) => [
            'account_code' => $line->account->code,
            'description' => (string) $line->description,
            'debit' => $line->debitAmount()->isPositive() ? $line->debitAmount()->round()->toString() : '',
            'credit' => $line->creditAmount()->isPositive() ? $line->creditAmount()->round()->toString() : '',
        ])->all();
    }

    private function sumColumn(string $column): Money
    {
        return Money::sum(array_map(
            fn (array $line) => $this->toMoney($line[$column] ?? ''),
            $this->lines,
        ));
    }

    private function toMoney(mixed $value): Money
    {
        $value = is_string($value) ? trim($value) : $value;

        if ($value === '' || $value === null) {
            return Money::zero();
        }

        // El formulario puede traer basura mientras se teclea; el total en vivo
        // no debe romper la pantalla por ello.
        return is_numeric($value) ? Money::of((string) $value) : Money::zero();
    }

    /**
     * @return array{account_code: string, description: string, debit: string, credit: string}
     */
    private function emptyLine(): array
    {
        return ['account_code' => '', 'description' => '', 'debit' => '', 'credit' => ''];
    }
}
