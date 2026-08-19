<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Inventory\Models\StockTransfer;
use App\Domains\Inventory\Services\InventoryService;
use App\Domains\Inventory\Services\StockTransferService;
use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Traslado entre bodegas')]
class TransferForm extends Component
{
    public ?int $transferId = null;

    public ?int $branch_id = null;

    public ?int $from_warehouse_id = null;

    public ?int $to_warehouse_id = null;

    public string $date = '';

    public string $notes = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $lines = [];

    public function mount(?int $transfer = null): void
    {
        $context = app(CompanyContext::class);

        $this->date = now()->toDateString();
        $this->branch_id = $context->branchId() ?? Branch::query()->where('is_main', true)->value('id');

        $warehouses = Warehouse::query()->active()
            ->orderByDesc('is_default')->orderBy('code')->pluck('id')->all();

        $this->from_warehouse_id = $warehouses[0] ?? null;
        $this->to_warehouse_id = $warehouses[1] ?? null;

        if ($transfer !== null) {
            $this->loadTransfer($transfer);

            return;
        }

        $this->authorize('create', StockTransfer::class);
        $this->lines = [$this->emptyLine()];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'from_warehouse_id' => ['required', 'integer'],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id'],
            'date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'to_warehouse_id.different' => 'La bodega de destino tiene que ser distinta de la de origen.',
            'lines.min' => 'El traslado necesita al menos una línea.',
            'lines.*.product_id.required' => 'Falta el producto en una de las líneas.',
        ];
    }

    public function updated(string $property, mixed $value): void
    {
        // Al cambiar la bodega de origen hay que refrescar todas las
        // existencias mostradas, no solo la de la línea tocada.
        if ($property === 'from_warehouse_id') {
            foreach (array_keys($this->lines) as $index) {
                $this->refreshOnHand($index);
            }

            return;
        }

        if (str_starts_with($property, 'lines.') && str_ends_with($property, '.product_id')) {
            [, $index] = explode('.', $property);
            $this->refreshOnHand((int) $index);
        }
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

    public function saveDraft(StockTransferService $transfers): void
    {
        $this->persist($transfers, post: false);
    }

    public function saveAndPost(StockTransferService $transfers): void
    {
        $this->persist($transfers, post: true);
    }

    public function render(): View
    {
        return view('livewire.inventory.transfer-form', [
            'products' => Product::query()->where('track_inventory', true)
                ->active()->orderBy('code')->get(['id', 'code', 'name']),
            'branches' => Branch::query()->active()->orderBy('code')->get(),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
        ]);
    }

    private function refreshOnHand(int $index): void
    {
        $productId = $this->lines[$index]['product_id'] ?? null;

        $this->lines[$index]['on_hand'] = $productId === null || $this->from_warehouse_id === null
            ? null
            : app(InventoryService::class)->availableQuantity((int) $productId, $this->from_warehouse_id);
    }

    private function persist(StockTransferService $transfers, bool $post): void
    {
        $this->validate();

        $header = [
            'branch_id' => $this->branch_id,
            'from_warehouse_id' => $this->from_warehouse_id,
            'to_warehouse_id' => $this->to_warehouse_id,
            'date' => $this->date,
            'notes' => $this->notes ?: null,
        ];

        $lines = array_map(fn (array $line) => [
            'product_id' => $line['product_id'],
            'quantity' => $this->numeric($line['quantity']),
        ], $this->lines);

        try {
            if ($this->transferId !== null) {
                $transfer = StockTransfer::query()->findOrFail($this->transferId);
                $this->authorize('update', $transfer);
                $transfer = $transfers->updateDraft($transfer, $header, $lines);
            } else {
                $this->authorize('create', StockTransfer::class);
                $transfer = $transfers->saveDraft($header, $lines);
                $this->transferId = $transfer->id;
            }

            if ($post) {
                $this->authorize('post', $transfer);
                $transfer = $transfers->post($transfer);
                session()->flash('success', "Traslado {$transfer->number} aplicado.");
            } else {
                session()->flash('success', 'Borrador guardado.');
            }

            $this->redirectRoute('inventory.transfers.index', navigate: true);
        } catch (InventoryException $e) {
            $this->addError('lines', $e->getMessage());
        }
    }

    private function loadTransfer(int $transferId): void
    {
        $transfer = StockTransfer::query()->with('items.product')->findOrFail($transferId);
        $this->authorize('update', $transfer);

        $this->transferId = $transfer->id;
        $this->branch_id = $transfer->branch_id;
        $this->from_warehouse_id = $transfer->from_warehouse_id;
        $this->to_warehouse_id = $transfer->to_warehouse_id;
        $this->date = $transfer->date->toDateString();
        $this->notes = (string) $transfer->notes;

        $inventory = app(InventoryService::class);

        $this->lines = $transfer->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'quantity' => rtrim(rtrim((string) $item->quantity, '0'), '.'),
            'on_hand' => $inventory->availableQuantity($item->product_id, $transfer->from_warehouse_id),
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
            'quantity' => '1',
            'on_hand' => null,
        ];
    }
}
