<?php

declare(strict_types=1);

namespace App\Livewire\Catalog;

use App\Domains\Catalog\Models\PriceList;
use App\Domains\Catalog\Models\ProductCategory;
use App\Domains\Catalog\Models\Unit;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Unidades de medida, categorías de producto y listas de precios.
 *
 * ## Por qué las tres en una pantalla
 *
 * Son tres tablas de código y nombre que se llenan el día que se instala el
 * sistema y casi no se vuelven a tocar. Tres entradas de menú para eso llenarían
 * el menú de ruido; en pestañas, quien está configurando la empresa las llena de
 * corrido.
 *
 * ## Lo que esto desbloquea
 *
 * Hasta ahora `CatalogProvisioner` sembraba siete unidades y tres listas de
 * precios, y **ninguna categoría de producto**. Ni unas ni otras tenían pantalla,
 * así que el selector de categoría del formulario de producto no podía llenarse
 * nunca, y una panadería que vende por docena no tenía cómo agregar su unidad.
 */
#[Title('Catálogos')]
class CatalogIndex extends Component
{
    /**
     * El plural va escrito, no calculado: `Str::plural` es un pluralizador
     * inglés y de «unidad» saca «unidads».
     */
    public const TABS = [
        'unidades' => [
            'model' => Unit::class,
            'table' => 'units',
            'title' => 'Unidades de medida',
            'singular' => 'unidad',
            'plural' => 'unidades',
            'hint' => 'Cómo se vende cada producto: por unidad, por libra, por caja.',
            'codeHint' => 'UND, CJA, LB…',
        ],
        'categorias' => [
            'model' => ProductCategory::class,
            'table' => 'product_categories',
            'title' => 'Categorías de producto',
            'singular' => 'categoría',
            'plural' => 'categorías',
            'hint' => 'Para agrupar el catálogo y leer las ventas por familia.',
            'codeHint' => 'FERR, PINT…',
        ],
        'precios' => [
            'model' => PriceList::class,
            'table' => 'price_lists',
            'title' => 'Listas de precios',
            'singular' => 'lista de precios',
            'plural' => 'listas de precios',
            'hint' => 'Un mismo producto con precio distinto según a quién se le venda.',
            'codeHint' => 'DET, MAY…',
        ],
    ];

    #[Url(as: 'tipo', except: 'unidades')]
    public string $tab = 'unidades';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    /** Solo aplica a listas de precios. */
    public bool $is_default = false;

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('viewAny', Unit::class);
        $this->guardTab();
    }

    public function updatedTab(): void
    {
        $this->guardTab();
        $this->closeForm();
    }

    public function create(): void
    {
        $this->authorize('create', $this->modelClass());

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $record = $this->find($id);
        $this->authorize('update', $record);

        $this->editingId = $record->id;
        $this->code = $record->code;
        $this->name = $record->name;
        $this->is_active = (bool) $record->is_active;
        $this->is_default = $this->isPriceList() && (bool) $record->is_default;
        $this->showForm = true;
        $this->resetValidation();
    }

    public function save(): void
    {
        $config = self::TABS[$this->tab];

        $data = $this->validate([
            'code' => [
                'required', 'string', 'max:20',
                // El código es único **por empresa**, no en toda la tabla: dos
                // clientes distintos pueden tener los dos su unidad «CJA».
                Rule::unique($config['table'], 'code')
                    ->where('company_id', app(CompanyContext::class)->idOrFail())
                    ->ignore($this->editingId),
            ],
            'name' => ['required', 'string', 'max:120'],
        ], attributes: [
            'code' => 'código',
            'name' => 'nombre',
        ]);

        $data['is_active'] = $this->is_active;

        if ($this->isPriceList()) {
            $data['is_default'] = $this->is_default;
        }

        DB::transaction(function () use ($data): void {
            if ($this->editingId === null) {
                $this->authorize('create', $this->modelClass());
                $record = new ($this->modelClass());
            } else {
                $record = $this->find($this->editingId);
                $this->authorize('update', $record);
            }

            $record->fill($data)->save();

            if ($this->isPriceList() && $this->is_default) {
                $this->makeSoleDefault($record);
            }
        });

        session()->flash('success', ucfirst($config['singular']).' guardada.');

        $this->closeForm();
    }

    /**
     * Activa o desactiva. **No hay borrar**: un producto vendido hace dos años
     * apunta a su unidad y a su categoría, y borrarlas dejaría documentos
     * históricos hablando de algo que ya no existe.
     */
    public function toggleActive(int $id): void
    {
        $record = $this->find($id);
        $this->authorize('deactivate', $record);

        // La lista de precios predeterminada no se puede apagar: el formulario
        // de venta necesita una con la que arrancar.
        if ($this->isPriceList() && $record->is_active && $record->is_default) {
            session()->flash('error', 'No se puede desactivar la lista de precios predeterminada. Nombrá otra antes.');

            return;
        }

        $record->forceFill(['is_active' => ! $record->is_active])->save();

        session()->flash('success', $record->is_active ? 'Quedó activa.' : 'Quedó desactivada y ya no se ofrece.');
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
        $this->resetValidation();
    }

    public function render(): View
    {
        $this->authorize('viewAny', Unit::class);

        return view('livewire.catalog.catalog-index', [
            'config' => self::TABS[$this->tab],
            'records' => $this->records(),
            'isPriceList' => $this->isPriceList(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Interno
    |--------------------------------------------------------------------------
    */

    /**
     * @return Collection<int, Model>
     */
    private function records(): Collection
    {
        return $this->modelClass()::query()->orderBy('code')->get();
    }

    private function find(int $id): Model
    {
        return $this->modelClass()::query()->findOrFail($id);
    }

    /**
     * @return class-string<Model>
     */
    private function modelClass(): string
    {
        return self::TABS[$this->tab]['model'];
    }

    private function isPriceList(): bool
    {
        return $this->tab === 'precios';
    }

    /**
     * Solo una lista puede ser la predeterminada.
     *
     * Se quita la marca a las demás en la misma transacción que la pone: si dos
     * quedaran marcadas, el formulario de venta elegiría una al azar y el mismo
     * producto se cobraría a precios distintos según el orden de la consulta.
     */
    private function makeSoleDefault(Model $record): void
    {
        PriceList::query()
            ->whereKeyNot($record->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'code', 'name', 'is_default']);
        $this->is_active = true;
    }

    /**
     * La pestaña llega por la URL, así que puede venir con cualquier cosa.
     */
    private function guardTab(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'unidades';
        }
    }
}
