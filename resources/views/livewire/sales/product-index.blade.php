@php $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Productos y servicios</h2>
            <p class="text-sm text-slate-500">
                {{ $products->total() }} en el catálogo. Las existencias llegan en la Fase 5.
            </p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Código, código de barras o nombre…"
                   class="{{ $input }} w-72">
            @can('create', \App\Domains\Catalog\Models\Product::class)
                <button type="button" wire:click="create"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Nuevo producto
                </button>
            @endcan
        </div>
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Código</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Nombre</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Tipo</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Impuesto</th>
                    @foreach ($priceLists as $list)
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">{{ $list->name }}</th>
                    @endforeach
                    @if ($canSeeCost)
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Costo</th>
                    @endif
                    <th role="columnheader" class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($products as $product)
                    <tr role="row" class="hover:bg-slate-50 {{ $product->is_active ? '' : 'opacity-50' }}">
                        <td role="cell" data-label="Código" class="px-4 py-1.5 font-mono text-xs">{{ $product->code }}</td>
                        <td role="cell" data-label="Nombre" class="px-4 py-1.5 font-medium">{{ $product->name }}</td>
                        <td role="cell" data-label="Tipo" class="px-4 py-1.5 text-slate-600">
                            {{ $product->isService() ? 'Servicio' : 'Producto' }}
                        </td>
                        <td role="cell" data-label="Impuesto" class="px-4 py-1.5 text-slate-600">{{ $product->tax?->label() ?? '—' }}</td>
                        @foreach ($priceLists as $list)
                            <td role="cell" data-label="{{ $list->name }}" class="px-4 py-1.5 text-right font-mono">
                                {{ $product->priceIn($list->id)?->format() ?? '—' }}
                            </td>
                        @endforeach
                        @if ($canSeeCost)
                            <td role="cell" data-label="Costo" class="px-4 py-1.5 text-right font-mono text-slate-600">
                                {{ $product->costAmount()->format() }}
                            </td>
                        @endif
                        <td role="cell" class="px-4 py-1.5 text-right whitespace-nowrap">
                            @can('update', $product)
                                <button type="button" wire:click="edit({{ $product->id }})"
                                        class="text-xs text-slate-600 underline hover:text-slate-900">Editar</button>
                            @endcan
                            @can('delete', $product)
                                <button type="button" wire:click="delete({{ $product->id }})"
                                        wire:confirm="¿Eliminar {{ $product->name }}?"
                                        class="ml-2 text-xs text-red-600 underline hover:text-red-800">Eliminar</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="{{ 5 + $priceLists->count() + ($canSeeCost ? 1 : 0) }}"
                            class="px-4 py-8 text-center text-slate-500">Sin productos.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $products->links() }}</div>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar producto' : 'Nuevo producto'">
            <form wire:submit="save">
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Código (SKU)" for="code" error="code">
                        <input id="code" type="text" wire:model="code" autofocus class="{{ $input }} font-mono">
                    </x-field>

                    <x-field label="Código de barras" for="barcode" error="barcode">
                        <input id="barcode" type="text" wire:model="barcode" class="{{ $input }} font-mono">
                    </x-field>

                    <x-field label="Nombre" for="name" error="name" class="sm:col-span-2">
                        <input id="name" type="text" wire:model="name" class="{{ $input }}">
                    </x-field>

                    <x-field label="Tipo" for="type" error="type">
                        <select id="type" wire:model.live="type" class="{{ $input }}">
                            <option value="product">Producto</option>
                            <option value="service">Servicio</option>
                        </select>
                    </x-field>

                    <x-field label="Unidad" for="unit_id" error="unit_id">
                        <select id="unit_id" wire:model="unit_id" class="{{ $input }}">
                            <option value="">Sin unidad</option>
                            @foreach ($units as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Categoría" for="product_category_id" error="product_category_id">
                        <select id="product_category_id" wire:model="product_category_id" class="{{ $input }}">
                            <option value="">Sin categoría</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Impuesto" for="tax_id" error="tax_id">
                        <select id="tax_id" wire:model="tax_id" class="{{ $input }}">
                            <option value="">Sin impuesto</option>
                            @foreach ($taxes as $tax)
                                <option value="{{ $tax->id }}">{{ $tax->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    @if ($canSeeCost)
                        <x-field label="Costo de referencia" for="cost" error="cost"
                                 hint="En la Fase 5 lo sustituye el costo promedio del kardex.">
                            <input id="cost" type="text" inputmode="decimal" wire:model="cost"
                                   class="{{ $input }} text-right font-mono">
                        </x-field>
                    @endif

                    <div class="space-y-2 self-end text-sm text-slate-700">
                        @if ($type === 'product')
                            <label class="flex items-center gap-2">
                                <input type="checkbox" wire:model="track_inventory"
                                       class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                                Llevará control de existencias
                            </label>
                        @endif
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model="is_active"
                                   class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                            Producto activo
                        </label>
                    </div>

                    <div class="sm:col-span-2">
                        <p class="mb-2 text-sm font-medium text-slate-700">Precios de venta</p>
                        <div class="grid gap-3 sm:grid-cols-3">
                            @foreach ($priceLists as $list)
                                <x-field :label="$list->name" :for="'price-'.$list->id" :error="'prices.'.$list->id">
                                    <input id="price-{{ $list->id }}" type="text" inputmode="decimal"
                                           wire:model="prices.{{ $list->id }}"
                                           class="{{ $input }} text-right font-mono" placeholder="—">
                                </x-field>
                            @endforeach
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            Dejar en blanco significa que el producto no se ofrece en esa lista.
                        </p>
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancel"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Guardar
                    </button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
