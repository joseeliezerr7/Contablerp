<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Enums\AdjustmentReason;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Inventory\Models\StockAdjustment;
use App\Domains\Inventory\Services\InventoryService;
use App\Domains\Inventory\Services\StockAdjustmentService;
use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Captura de ajustes.
 *
 * La cantidad se teclea con signo: positiva si sobra, negativa si falta. Se
 * eligió eso en vez de un selector de «entrada/salida» porque un conteo físico
 * se transcribe línea a línea desde una hoja donde ya viene la diferencia.
 */
#[Title('Ajuste de inventario')]
class AdjustmentForm extends Component
{
    public ?int $adjustmentId = null;

    public ?int $branch_id = null;

    public ?int $warehouse_id = null;

    public string $date = '';

    public string $reason = 'count';

    public ?int $adjustment_account_id = null;

    public string $notes = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $lines = [];

    public function mount(?int $adjustment = null): void
    {
        $context = app(CompanyContext::class);

        $this->date = now()->toDateString();
        $this->branch_id = $context->branchId() ?? Branch::query()->where('is_main', true)->value('id');
        $this->warehouse_id = Warehouse::query()->active()
            ->orderByDesc('is_default')->orderBy('code')->value('id');

        if ($adjustment !== null) {
            $this->loadAdjustment($adjustment);

            return;
        }

        $this->authorize('create', StockAdjustment::class);
        $this->lines = [$this->emptyLine()];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'reason' => ['required', 'in:'.implode(',', AdjustmentReason::values())],
            'adjustment_account_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'not_in:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'warehouse_id.required' => 'Indica la bodega que se ajusta.',
            'lines.min' => 'El ajuste necesita al menos una línea.',
            'lines.*.product_id.required' => 'Falta el producto en una de las líneas.',
            'lines.*.quantity.not_in' => 'Una cantidad en cero no ajusta nada.',
        ];
    }

    public function updated(string $property, mixed $value): void
    {
        if (! str_starts_with($property, 'lines.') || ! str_ends_with($property, '.product_id')) {
            return;
        }

        [, $index] = explode('.', $property);
        $index = (int) $index;

        // Se muestra la existencia actual, que es contra lo que el usuario
        // compara su conteo.
        $this->lines[$index]['on_hand'] = $value === null || $this->warehouse_id === null
            ? null
            : app(InventoryService::class)->availableQuantity((int) $value, $this->warehouse_id);
    }

    public function addLine(): void
    {
        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) <= 1) {
            return;
        }

        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function saveDraft(StockAdjustmentService $adjustments): void
    {
        $this->persist($adjustments, post: false);
    }

    public function saveAndPost(StockAdjustmentService $adjustments): void
    {
        $this->persist($adjustments, post: true);
    }

    public function render(): View
    {
        return view('livewire.inventory.adjustment-form', [
            'products' => Product::query()->where('track_inventory', true)
                ->active()->orderBy('code')->get(['id', 'code', 'name']),
            'branches' => Branch::query()->active()->orderBy('code')->get(),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'reasons' => AdjustmentReason::cases(),
            'adjustmentAccounts' => Account::query()->postable()
                ->whereIn('type', [AccountType::Expense, AccountType::Cost])
                ->orderBy('code')->get(),
        ]);
    }

    private function persist(StockAdjustmentService $adjustments, bool $post): void
    {
        $this->validate();

        $header = [
            'branch_id' => $this->branch_id,
            'warehouse_id' => $this->warehouse_id,
            'date' => $this->date,
            'reason' => $this->reason,
            'adjustment_account_id' => $this->adjustment_account_id,
            'notes' => $this->notes ?: null,
        ];

        $lines = array_map(fn (array $line) => [
            'product_id' => $line['product_id'],
            'quantity' => $this->numeric($line['quantity']),
            'unit_cost' => $this->numeric($line['unit_cost'] ?? '0'),
            'description' => $line['description'] ?? null,
        ], $this->lines);

        try {
            if ($this->adjustmentId !== null) {
                $adjustment = StockAdjustment::query()->findOrFail($this->adjustmentId);
                $this->authorize('update', $adjustment);
                $adjustment = $adjustments->updateDraft($adjustment, $header, $lines);
            } else {
                $this->authorize('create', StockAdjustment::class);
                $adjustment = $adjustments->saveDraft($header, $lines);
                $this->adjustmentId = $adjustment->id;
            }

            if ($post) {
                $this->authorize('post', $adjustment);
                $adjustment = $adjustments->post($adjustment);
                session()->flash('success', "Ajuste {$adjustment->number} aplicado.");
            } else {
                session()->flash('success', 'Borrador guardado.');
            }

            $this->redirectRoute('inventory.adjustments.index', navigate: true);
        } catch (InventoryException $e) {
            $this->addError('lines', $e->getMessage());
        }
    }

    private function loadAdjustment(int $adjustmentId): void
    {
        $adjustment = StockAdjustment::query()->with('items.product')->findOrFail($adjustmentId);
        $this->authorize('update', $adjustment);

        $this->adjustmentId = $adjustment->id;
        $this->branch_id = $adjustment->branch_id;
        $this->warehouse_id = $adjustment->warehouse_id;
        $this->date = $adjustment->date->toDateString();
        $this->reason = $adjustment->reason->value;
        $this->adjustment_account_id = $adjustment->adjustment_account_id;
        $this->notes = (string) $adjustment->notes;

        $inventory = app(InventoryService::class);

        $this->lines = $adjustment->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'description' => $item->description,
            'quantity' => rtrim(rtrim((string) $item->quantity, '0'), '.'),
            'unit_cost' => (string) round((float) $item->unit_cost, 2),
            'on_hand' => $inventory->availableQuantity($item->product_id, $adjustment->warehouse_id),
        ])->all();
    }

    private function numeric(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : $value;

        return is_numeric($value) ? (string) $value : '0';
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyLine(): array
    {
        return [
            'product_id' => null,
            'description' => '',
            'quantity' => '0',
            'unit_cost' => '0',
            'on_hand' => null,
        ];
    }
}
