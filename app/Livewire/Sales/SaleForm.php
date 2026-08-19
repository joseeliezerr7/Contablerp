<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Domains\Accounting\Models\Account;
use App\Domains\Catalog\Models\Product;
use App\Domains\Partners\Models\Customer;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleService;
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
 * Captura de facturas.
 *
 * El producto se escribe por código con autocompletado del navegador, igual que
 * las cuentas en el libro diario: en el mostrador se teclea el código o se pasa
 * el lector de barras, no se busca en una lista.
 */
#[Title('Factura de venta')]
class SaleForm extends Component
{
    public ?int $saleId = null;

    public ?int $branch_id = null;

    public ?int $warehouse_id = null;

    public ?int $customer_id = null;

    public string $date = '';

    public string $payment_condition = 'cash';

    public int $credit_days = 0;

    public ?int $deposit_account_id = null;

    public string $reference = '';

    public string $notes = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $lines = [];

    public function mount(?int $sale = null): void
    {
        $context = app(CompanyContext::class);

        $this->date = now()->toDateString();
        $this->branch_id = $context->branchId() ?? Branch::query()->where('is_main', true)->value('id');
        $this->deposit_account_id = Account::query()->cash()->orderBy('code')->value('id');
        $this->warehouse_id = Warehouse::query()->active()
            ->orderByDesc('is_default')->orderBy('code')->value('id');

        if ($sale !== null) {
            $this->loadSale($sale);

            return;
        }

        $this->authorize('create', Sale::class);
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
            'customer_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'payment_condition' => ['required', 'in:cash,credit'],
            'credit_days' => ['required_if:payment_condition,credit', 'integer', 'min:0', 'max:365'],
            'deposit_account_id' => ['required_if:payment_condition,cash', 'nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_rate' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.description' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'customer_id.required' => 'Selecciona el cliente.',
            'lines.min' => 'La factura necesita al menos una línea.',
            'lines.*.quantity.gt' => 'La cantidad debe ser mayor que cero.',
            'lines.*.description.required' => 'Falta la descripción en una de las líneas.',
            'deposit_account_id.required_if' => 'Indica la cuenta de caja o banco de la venta de contado.',
        ];
    }

    /**
     * Al elegir el cliente se toman sus condiciones y su lista de precios.
     */
    public function updatedCustomerId(): void
    {
        $customer = $this->customer;

        if ($customer === null) {
            return;
        }

        if ($customer->hasCredit()) {
            $this->payment_condition = PaymentCondition::Credit->value;
            $this->credit_days = $customer->credit_days;
        } else {
            $this->payment_condition = PaymentCondition::Cash->value;
            $this->credit_days = 0;
        }

        $this->repriceLines();
    }

    /**
     * Al escribir el código del producto se completan descripción, precio e
     * impuesto.
     *
     * Se usa el hook genérico `updated()` y no `updatedLines()`: para
     * propiedades anidadas como `lines.0.product_code`, el hook por propiedad
     * no se dispara y el autocompletado quedaría muerto sin dar ningún error.
     */
    public function updated(string $property, mixed $value): void
    {
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
            ->with('prices')
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
        $this->lines[$index]['unit_price'] = (string) ($product->priceIn($this->priceListId())?->round(2)->toString() ?? '0');
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

    #[Computed]
    public function customer(): ?Customer
    {
        return $this->customer_id === null
            ? null
            : Customer::query()->find($this->customer_id);
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

    public function saveDraft(SaleService $sales): void
    {
        $this->persist($sales, issue: false);
    }

    public function saveAndIssue(SaleService $sales): void
    {
        $this->persist($sales, issue: true);
    }

    public function render(): View
    {
        return view('livewire.sales.sale-form', [
            'customers' => Customer::query()->active()->orderBy('name')->get(),
            'products' => Product::query()->active()->orderBy('code')->get(['id', 'code', 'name']),
            'branches' => Branch::query()->active()->orderBy('code')->get(),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'taxes' => Tax::query()->active()->orderBy('name')->get(),
            'cashAccounts' => Account::query()->cash()->orderBy('code')->get(),
        ]);
    }

    private function persist(SaleService $sales, bool $issue): void
    {
        $this->validate();

        $header = [
            'branch_id' => $this->branch_id,
            'warehouse_id' => $this->warehouse_id,
            'customer_id' => $this->customer_id,
            'date' => $this->date,
            'payment_condition' => $this->payment_condition,
            'credit_days' => $this->credit_days,
            'deposit_account_id' => $this->deposit_account_id,
            'reference' => $this->reference ?: null,
            'notes' => $this->notes ?: null,
        ];

        $lines = array_map(fn (array $line) => [
            'product_id' => $line['product_id'] ?? null,
            'description' => $line['description'],
            'quantity' => $this->numeric($line['quantity']),
            'unit_price' => $this->numeric($line['unit_price']),
            'discount_rate' => $this->numeric($line['discount_rate'] ?? '0'),
            'tax_id' => $line['tax_id'] ?? null,
        ], $this->lines);

        try {
            if ($this->saleId !== null) {
                $sale = Sale::query()->findOrFail($this->saleId);
                $this->authorize('update', $sale);
                $sale = $sales->updateDraft($sale, $header, $lines);
            } else {
                $this->authorize('create', Sale::class);
                $sale = $sales->saveDraft($header, $lines);
                $this->saleId = $sale->id;
            }

            if ($issue) {
                $this->authorize('issue', $sale);

                $override = auth()->user()->can('sales.invoices.override_credit_limit');
                $sale = $sales->issue($sale, $override);

                session()->flash('success', "Factura {$sale->number} emitida.");
            } else {
                session()->flash('success', 'Borrador guardado.');
            }

            $this->redirectRoute('sales.index', navigate: true);
        } catch (SalesException $e) {
            $this->addError('lines', $e->getMessage());
        }
    }

    private function loadSale(int $saleId): void
    {
        $sale = Sale::query()->with('items.product')->findOrFail($saleId);
        $this->authorize('update', $sale);

        $this->saleId = $sale->id;
        $this->branch_id = $sale->branch_id;
        $this->warehouse_id = $sale->warehouse_id;
        $this->customer_id = $sale->customer_id;
        $this->date = $sale->date->toDateString();
        $this->payment_condition = $sale->payment_condition->value;
        $this->credit_days = $sale->credit_days;
        $this->deposit_account_id = $sale->deposit_account_id;
        $this->reference = (string) $sale->reference;
        $this->notes = (string) $sale->notes;

        $this->lines = $sale->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'product_code' => $item->product?->code ?? '',
            'description' => $item->description,
            'quantity' => rtrim(rtrim((string) $item->quantity, '0'), '.'),
            'unit_price' => (string) round((float) $item->unit_price, 2),
            'discount_rate' => rtrim(rtrim((string) $item->discount_rate, '0'), '.') ?: '0',
            'tax_id' => $item->tax_id,
        ])->all();
    }

    private function priceListId(): ?int
    {
        return $this->customer?->effectivePriceListId();
    }

    /**
     * Vuelve a poner los precios de la lista del cliente al cambiarlo.
     */
    private function repriceLines(): void
    {
        $listId = $this->priceListId();

        foreach ($this->lines as $index => $line) {
            if (empty($line['product_id'])) {
                continue;
            }

            $product = Product::query()->with('prices')->find($line['product_id']);
            $price = $product?->priceIn($listId);

            if ($price !== null) {
                $this->lines[$index]['unit_price'] = $price->round(2)->toString();
            }
        }
    }

    /**
     * El formulario puede traer texto a medio escribir; los totales en vivo no
     * deben romper la pantalla por ello.
     */
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
        ];
    }
}
