<?php

declare(strict_types=1);

namespace App\Livewire\Purchases;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Catalog\Models\Product;
use App\Domains\Partners\Models\Supplier;
use App\Domains\Purchases\Exceptions\PurchaseException;
use App\Domains\Purchases\Models\Purchase;
use App\Domains\Purchases\Services\PurchaseService;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Taxation\Services\TaxResolver;
use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Warehouse;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Captura de compras. A diferencia de la factura de venta, aquí el precio no se
 * propone: lo dicta la factura del proveedor y se teclea tal cual viene.
 */
#[Title('Compra a proveedor')]
class PurchaseForm extends Component
{
    public ?int $purchaseId = null;

    public ?int $branch_id = null;

    public ?int $warehouse_id = null;

    public ?int $supplier_id = null;

    public string $supplier_invoice_number = '';

    public string $date = '';

    public string $payment_condition = 'credit';

    public int $credit_days = 30;

    public ?int $payment_account_id = null;

    public string $notes = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $lines = [];

    public function mount(?int $purchase = null): void
    {
        $context = app(CompanyContext::class);

        $this->date = now()->toDateString();
        $this->branch_id = $context->branchId() ?? Branch::query()->where('is_main', true)->value('id');
        $this->payment_account_id = Account::query()->cash()->orderBy('code')->value('id');
        $this->warehouse_id = Warehouse::query()->active()
            ->orderByDesc('is_default')->orderBy('code')->value('id');

        if ($purchase !== null) {
            $this->loadPurchase($purchase);

            return;
        }

        $this->authorize('create', Purchase::class);
        $this->lines = [$this->emptyLine()];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'supplier_id' => ['required', 'integer'],
            'supplier_invoice_number' => ['required', 'string', 'max:40'],
            'date' => ['required', 'date'],
            'payment_condition' => ['required', 'in:cash,credit'],
            'credit_days' => ['required_if:payment_condition,credit', 'integer', 'min:0', 'max:365'],
            'payment_account_id' => ['required_if:payment_condition,cash', 'nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_rate' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'supplier_id.required' => 'Selecciona el proveedor.',
            'supplier_invoice_number.required' => 'Indica el número de la factura del proveedor.',
            'lines.min' => 'La compra necesita al menos una línea.',
            'lines.*.description.required' => 'Falta la descripción en una de las líneas.',
            'payment_account_id.required_if' => 'Indica la cuenta de la que sale el dinero.',
        ];
    }

    public function updated(string $property, mixed $value): void
    {
        if ($property === 'supplier_id') {
            $this->credit_days = Supplier::query()->find($value)?->credit_days ?? 30;

            return;
        }

        if (! str_starts_with($property, 'lines.') || ! str_ends_with($property, '.product_code')) {
            return;
        }

        [, $index] = explode('.', $property);
        $index = (int) $index;
        $code = trim((string) $value);

        if ($code === '') {
            return;
        }

        $product = Product::query()->active()
            ->where(fn ($q) => $q->where('code', $code)->orWhere('barcode', $code))
            ->first();

        if ($product === null) {
            $this->addError("lines.{$index}.product_code", "No existe el producto «{$code}».");

            return;
        }

        $this->resetValidation("lines.{$index}.product_code");

        $this->lines[$index]['product_id'] = $product->id;
        $this->lines[$index]['product_code'] = $product->code;
        $this->lines[$index]['description'] = $product->name;
        $this->lines[$index]['tax_id'] = $product->tax_id;
        // El costo anterior se sugiere como punto de partida, pero el precio
        // real lo dicta la factura del proveedor.
        $this->lines[$index]['unit_price'] = (string) round((float) $product->cost, 2);
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

    /**
     * @return array{subtotal: Money, discount: Money, tax: Money, total: Money}
     */
    #[Computed]
    public function totals(): array
    {
        $resolver = app(TaxResolver::class);
        $taxes = Tax::query()->get()->keyBy('id');

        $calculations = array_map(
            fn (array $line) => $resolver->calculateLine(
                $this->numeric($line['quantity'] ?? '0'),
                $this->numeric($line['unit_price'] ?? '0'),
                $this->numeric($line['discount_rate'] ?? '0'),
                isset($line['tax_id']) ? $taxes->get($line['tax_id']) : null,
            ),
            $this->lines,
        );

        return $resolver->totals($calculations);
    }

    public function saveDraft(PurchaseService $purchases): void
    {
        $this->persist($purchases, receive: false);
    }

    public function saveAndReceive(PurchaseService $purchases): void
    {
        $this->persist($purchases, receive: true);
    }

    public function render(): View
    {
        return view('livewire.purchases.purchase-form', [
            'suppliers' => Supplier::query()->active()->orderBy('name')->get(),
            'products' => Product::query()->active()->orderBy('code')->get(['id', 'code', 'name']),
            'branches' => Branch::query()->active()->orderBy('code')->get(),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'taxes' => Tax::query()->active()->orderBy('name')->get(),
            'cashAccounts' => Account::query()->cash()->orderBy('code')->get(),
            'expenseAccounts' => Account::query()->postable()
                ->whereIn('type', [AccountType::Expense, AccountType::Cost])
                ->orderBy('code')->get(),
        ]);
    }

    private function persist(PurchaseService $purchases, bool $receive): void
    {
        $this->validate();

        $header = [
            'branch_id' => $this->branch_id,
            'warehouse_id' => $this->warehouse_id,
            'supplier_id' => $this->supplier_id,
            'supplier_invoice_number' => $this->supplier_invoice_number,
            'date' => $this->date,
            'payment_condition' => $this->payment_condition,
            'credit_days' => $this->credit_days,
            'payment_account_id' => $this->payment_account_id,
            'notes' => $this->notes ?: null,
        ];

        $lines = array_map(fn (array $line) => [
            'product_id' => $line['product_id'] ?? null,
            'description' => $line['description'],
            'quantity' => $this->numeric($line['quantity']),
            'unit_price' => $this->numeric($line['unit_price']),
            'discount_rate' => $this->numeric($line['discount_rate'] ?? '0'),
            'tax_id' => $line['tax_id'] ?? null,
            'expense_account_id' => $line['expense_account_id'] ?? null,
        ], $this->lines);

        try {
            if ($this->purchaseId !== null) {
                $purchase = Purchase::query()->findOrFail($this->purchaseId);
                $this->authorize('update', $purchase);
                $purchase = $purchases->updateDraft($purchase, $header, $lines);
            } else {
                $this->authorize('create', Purchase::class);
                $purchase = $purchases->saveDraft($header, $lines);
                $this->purchaseId = $purchase->id;
            }

            if ($receive) {
                $this->authorize('receive', $purchase);
                $purchase = $purchases->receive($purchase);
                session()->flash('success', "Compra {$purchase->number} registrada.");
            } else {
                session()->flash('success', 'Borrador guardado.');
            }

            $this->redirectRoute('purchases.index', navigate: true);
        } catch (PurchaseException $e) {
            $this->addError('lines', $e->getMessage());
        }
    }

    private function loadPurchase(int $purchaseId): void
    {
        $purchase = Purchase::query()->with('items.product')->findOrFail($purchaseId);
        $this->authorize('update', $purchase);

        $this->purchaseId = $purchase->id;
        $this->branch_id = $purchase->branch_id;
        $this->warehouse_id = $purchase->warehouse_id;
        $this->supplier_id = $purchase->supplier_id;
        $this->supplier_invoice_number = $purchase->supplier_invoice_number;
        $this->date = $purchase->date->toDateString();
        $this->payment_condition = $purchase->payment_condition->value;
        $this->credit_days = $purchase->credit_days;
        $this->payment_account_id = $purchase->payment_account_id;
        $this->notes = (string) $purchase->notes;

        $this->lines = $purchase->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'product_code' => $item->product?->code ?? '',
            'description' => $item->description,
            'quantity' => rtrim(rtrim((string) $item->quantity, '0'), '.'),
            'unit_price' => (string) round((float) $item->unit_price, 2),
            'discount_rate' => rtrim(rtrim((string) $item->discount_rate, '0'), '.') ?: '0',
            'tax_id' => $item->tax_id,
            'expense_account_id' => $item->expense_account_id,
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
            'product_code' => '',
            'description' => '',
            'quantity' => '1',
            'unit_price' => '0',
            'discount_rate' => '0',
            'tax_id' => Tax::query()->where('is_default', true)->value('id'),
            'expense_account_id' => null,
        ];
    }
}
