<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Domains\Fiscal\Enums\FiscalDocumentType;
use App\Domains\Fiscal\Exceptions\FiscalException;
use App\Domains\Fiscal\Services\FiscalNumberService;
use App\Domains\Sales\Enums\CreditNoteReason;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\CreditNote;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\CreditNoteService;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Captura de una nota de crédito.
 *
 * Se parte siempre de una factura emitida y sus líneas: la nota no inventa
 * productos ni precios, elige cuánto se devuelve de lo que ya se facturó. Así
 * el costo que vuelve al inventario es el que salió, y no hay forma de acreditar
 * algo que nunca se vendió.
 */
#[Title('Nota de crédito')]
class CreditNoteForm extends Component
{
    public ?int $noteId = null;

    public string $saleNumber = '';

    public ?int $saleId = null;

    public string $date = '';

    public string $reason = 'return';

    public string $description = '';

    public bool $restocks = true;

    /** @var array<int, array<string, mixed>> */
    public array $lines = [];

    public function mount(?int $creditNote = null): void
    {
        $this->date = now()->toDateString();

        if ($creditNote === null) {
            $this->authorize('create', CreditNote::class);

            return;
        }

        $note = CreditNote::query()->with(['items', 'sale'])->findOrFail($creditNote);
        $this->authorize('update', $note);

        $this->noteId = $note->id;
        $this->saleId = $note->sale_id;
        $this->saleNumber = $note->sale->number;
        $this->date = $note->date->toDateString();
        $this->reason = $note->reason->value;
        $this->description = $note->description;
        $this->restocks = $note->restocks;

        $this->loadSaleLines($note->sale, $note);
    }

    /**
     * Busca la factura por su número fiscal y trae sus líneas.
     */
    public function findSale(): void
    {
        $sale = Sale::query()
            ->with(['items.product', 'items.tax', 'customer'])
            ->where('number', trim($this->saleNumber))
            ->first();

        if ($sale === null) {
            $this->addError('saleNumber', 'No existe una factura con ese número.');

            return;
        }

        if (! $sale->isIssued()) {
            $this->addError('saleNumber', SalesException::creditNoteNeedsIssuedSale($sale)->getMessage());

            return;
        }

        $this->resetValidation();
        $this->saleId = $sale->id;
        $this->loadSaleLines($sale);
    }

    public function save(CreditNoteService $service): void
    {
        $data = $this->validate([
            'saleId' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'reason' => ['required', Rule::in(CreditNoteReason::values())],
            'description' => ['required', 'string', 'min:5', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
        ], attributes: [
            'saleId' => 'factura',
            'date' => 'fecha',
            'reason' => 'motivo',
            'description' => 'descripción',
            'lines' => 'líneas',
        ]);

        // Solo se mandan las líneas con cantidad: una nota parcial no acredita
        // los renglones que el cliente se quedó.
        $lines = collect($this->lines)
            ->filter(fn (array $line) => (float) ($line['quantity'] ?? 0) > 0)
            ->map(fn (array $line) => [
                'sale_item_id' => $line['sale_item_id'],
                'quantity' => (string) $line['quantity'],
            ])
            ->values()
            ->all();

        if ($lines === []) {
            $this->addError('lines', 'Indicá al menos una cantidad a devolver.');

            return;
        }

        $sale = Sale::query()->findOrFail($this->saleId);

        try {
            $payload = [
                'date' => $data['date'],
                'reason' => $data['reason'],
                'description' => $data['description'],
                'restocks' => $this->restocks,
            ];

            $note = $this->noteId === null
                ? $service->saveDraft($sale, $payload, $lines)
                : $service->updateDraft(CreditNote::query()->findOrFail($this->noteId), $payload, $lines);

            session()->flash('success', 'Borrador guardado. Revisalo y emitilo desde la lista.');

            $this->redirectRoute('credit-notes.index', navigate: true);

            return;
        } catch (SalesException|FiscalException $e) {
            $this->addError('description', $e->getMessage());
        }
    }

    /**
     * Total de lo que se va a acreditar, calculado en el servidor.
     */
    public function previewTotal(): Money
    {
        $sale = $this->saleId !== null
            ? Sale::query()->with('items.tax')->find($this->saleId)
            : null;

        if ($sale === null) {
            return Money::zero();
        }

        $total = Money::zero();

        foreach ($this->lines as $line) {
            $quantity = (float) ($line['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $item = $sale->items->firstWhere('id', $line['sale_item_id']);

            if ($item === null) {
                continue;
            }

            // Proporción de la línea facturada, impuesto incluido.
            $proportion = bcdiv((string) $quantity, (string) $item->quantity, 8);
            $total = $total->plus(
                Money::ofRounded(bcmul($item->totalAmount()->toString(), $proportion, 8))
            );
        }

        return $total;
    }

    /**
     * @param  CreditNote|null  $note  Si viene, se rellenan las cantidades ya capturadas.
     */
    private function loadSaleLines(Sale $sale, ?CreditNote $note = null): void
    {
        $sale->loadMissing(['items.product', 'items.tax']);

        $captured = $note?->items->keyBy('sale_item_id') ?? collect();

        $this->lines = $sale->items->map(fn ($item) => [
            'sale_item_id' => $item->id,
            'description' => $item->description,
            'sold' => (string) $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'quantity' => (string) ($captured[$item->id]->quantity ?? '0'),
        ])->all();
    }

    public function render(FiscalNumberService $fiscal): View
    {
        $sale = $this->saleId !== null
            ? Sale::query()->with(['customer', 'branch'])->find($this->saleId)
            : null;

        return view('livewire.sales.credit-note-form', [
            'sale' => $sale,
            'reasons' => CreditNoteReason::cases(),
            'total' => $this->previewTotal(),
            // Se avisa **antes** de capturar: descubrir que no hay CAI de nota
            // de crédito después de llenar el formulario es perder el trabajo.
            'blocked' => $sale === null
                ? null
                : $fiscal->blockingReason($sale->branch, FiscalDocumentType::CreditNote, $this->date),
        ]);
    }
}
