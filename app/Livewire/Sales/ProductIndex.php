<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Domains\Catalog\Models\PriceList;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductCategory;
use App\Domains\Catalog\Models\ProductPrice;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Taxation\Models\Tax;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Productos y servicios')]
class ProductIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $code = '';

    public string $barcode = '';

    public string $name = '';

    public string $type = 'product';

    public ?int $unit_id = null;

    public ?int $product_category_id = null;

    public ?int $tax_id = null;

    public string $cost = '0';

    public bool $track_inventory = false;

    public bool $is_active = true;

    /**
     * Precio por lista: [price_list_id => precio].
     *
     * @var array<int, string>
     */
    public array $prices = [];

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('products', 'code')
                    ->where('company_id', app(CompanyContext::class)->idOrFail())
                    ->ignore($this->editingId)
                    ->whereNull('deleted_at'),
            ],
            'barcode' => ['nullable', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:200'],
            'type' => ['required', 'in:product,service'],
            'unit_id' => ['nullable', 'integer'],
            'product_category_id' => ['nullable', 'integer'],
            'tax_id' => ['nullable', 'integer'],
            'cost' => ['required', 'numeric', 'min:0'],
            'track_inventory' => ['boolean'],
            'is_active' => ['boolean'],
            'prices.*' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Un servicio nunca lleva control de existencias.
     */
    public function updatedType(string $value): void
    {
        if ($value === 'service') {
            $this->track_inventory = false;
        }
    }

    public function create(): void
    {
        $this->authorize('create', Product::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $product = Product::query()->with('prices')->findOrFail($id);
        $this->authorize('update', $product);

        $this->editingId = $product->id;
        $this->code = $product->code;
        $this->barcode = (string) $product->barcode;
        $this->name = $product->name;
        $this->type = $product->type;
        $this->unit_id = $product->unit_id;
        $this->product_category_id = $product->product_category_id;
        $this->tax_id = $product->tax_id;
        $this->cost = (string) $product->cost;
        $this->track_inventory = $product->track_inventory;
        $this->is_active = $product->is_active;

        $this->prices = $product->prices
            ->mapWithKeys(fn (ProductPrice $p) => [$p->price_list_id => (string) round((float) $p->price, 2)])
            ->all();

        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate();
        $prices = $data['prices'] ?? [];
        unset($data['prices']);

        DB::transaction(function () use ($data, $prices): void {
            if ($this->editingId !== null) {
                $product = Product::query()->findOrFail($this->editingId);
                $this->authorize('update', $product);
                $product->update($data);
            } else {
                $this->authorize('create', Product::class);
                $product = Product::create($data);
            }

            $this->savePrices($product, $prices);
        });

        session()->flash('success', $this->editingId !== null ? 'Producto actualizado.' : 'Producto creado.');

        $this->resetForm();
        $this->showForm = false;
    }

    public function delete(int $id): void
    {
        $product = Product::query()->findOrFail($id);
        $this->authorize('delete', $product);

        if (DB::table('sale_items')->where('product_id', $id)->exists()) {
            session()->flash('error', 'No se puede eliminar un producto con ventas. Desactívalo en su lugar.');

            return;
        }

        $product->delete();
        session()->flash('success', 'Producto eliminado.');
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function render(): View
    {
        $this->authorize('viewAny', Product::class);

        $priceLists = PriceList::query()->active()->orderBy('id')->get();

        return view('livewire.sales.product-index', [
            'products' => Product::query()
                ->with(['unit:id,code', 'tax:id,name,rate', 'prices'])
                ->when($this->search !== '', fn ($q) => $q->search($this->search))
                ->orderBy('code')
                ->paginate(20),
            'priceLists' => $priceLists,
            'units' => Unit::query()->active()->orderBy('code')->get(),
            'categories' => ProductCategory::query()->active()->orderBy('name')->get(),
            'taxes' => Tax::query()->active()->orderBy('name')->get(),
            'canSeeCost' => auth()->user()->can('viewCost', Product::class),
        ]);
    }

    /**
     * @param  array<int, string|null>  $prices
     */
    private function savePrices(Product $product, array $prices): void
    {
        foreach ($prices as $listId => $price) {
            // Un precio vacío significa «no listado»: el producto no se ofrece
            // en esa lista y el formulario de factura no propondrá precio.
            if ($price === null || $price === '') {
                $product->prices()->where('price_list_id', $listId)->delete();

                continue;
            }

            $existing = $product->prices()->where('price_list_id', $listId)->first();

            if ($existing !== null) {
                $existing->forceFill(['price' => $price])->save();

                continue;
            }

            $row = new ProductPrice;
            $row->forceFill([
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'price_list_id' => $listId,
                'price' => $price,
            ])->save();
        }
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'code', 'barcode', 'name', 'unit_id',
            'product_category_id', 'prices',
        ]);
        $this->type = 'product';
        $this->cost = '0';
        $this->tax_id = Tax::query()->where('is_default', true)->value('id');
        $this->track_inventory = false;
        $this->is_active = true;
        $this->resetValidation();
    }
}
