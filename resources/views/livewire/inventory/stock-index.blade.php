@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Existencias</h2>
            <p class="text-sm text-slate-500">
                Valor del inventario en el filtro actual:
                <span class="font-mono font-semibold">{{ $totalValue->format() }}</span>
            </p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Código o nombre…"
                   class="{{ $input }} w-56">
            <select wire:model.live="warehouseFilter" class="{{ $input }}">
                <option value="">Todas las bodegas</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->code }} — {{ $warehouse->name }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 px-2 text-sm text-slate-600">
                <input type="checkbox" wire:model.live="belowMinimumOnly"
                       class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                Bajo mínimo
            </label>
        </div>
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Código</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Producto</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Bodega</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Existencia</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Costo promedio</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Valor</th>
                    <th role="columnheader" class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($stocks as $stock)
                    <tr role="row" class="hover:bg-slate-50">
                        <td role="cell" data-label="Código" class="px-4 py-1.5 font-mono text-xs whitespace-nowrap">{{ $stock->product->code }}</td>
                        <td role="cell" data-label="Producto" class="px-4 py-1.5">
                            {{ $stock->product->name }}
                            @if ($stock->isBelowMinimum())
                                <span class="ml-2 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">
                                    Bajo mínimo
                                </span>
                            @endif
                        </td>
                        <td role="cell" data-label="Bodega" class="px-4 py-1.5 text-slate-600">{{ $stock->warehouse->code }}</td>
                        <td role="cell" data-label="Existencia" class="px-4 py-1.5 text-right font-mono">
                            {{ rtrim(rtrim($stock->quantity, '0'), '.') ?: '0' }}
                            <span class="text-xs text-slate-400">{{ $stock->product->unit?->code }}</span>
                        </td>
                        <td role="cell" data-label="Costo promedio" class="px-4 py-1.5 text-right font-mono text-slate-600">
                            {{ $stock->averageCost()->format() }}
                        </td>
                        <td role="cell" data-label="Valor" class="px-4 py-1.5 text-right font-mono">{{ $stock->valueAmount()->format() }}</td>
                        <td role="cell" class="px-4 py-1.5 text-right whitespace-nowrap">
                            @can('viewAny', \App\Domains\Inventory\Models\InventoryMovement::class)
                                <a href="{{ route('inventory.kardex', ['producto' => $stock->product_id, 'bodega' => $stock->warehouse_id]) }}"
                                   wire:navigate class="text-xs text-slate-600 underline hover:text-slate-900">Kardex</a>
                            @endcan
                            @can('update', $stock)
                                <button type="button" wire:click="editReorder({{ $stock->id }})"
                                        class="ml-2 text-xs text-slate-600 underline hover:text-slate-900">Reorden</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="7" class="px-4 py-8 text-center text-slate-500">
                            No hay existencias que coincidan con el filtro.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $stocks->links() }}</div>

    @if ($editingId)
        <x-modal title="Puntos de reorden" onClose="cancelReorder">
            <form wire:submit="saveReorder">
                <div class="space-y-3 p-5">
                    <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        Un mínimo en cero significa que este producto no se vigila; no que haya que
                        alertar siempre.
                    </p>

                    <x-field label="Mínimo" for="minQuantity" error="minQuantity">
                        <input id="minQuantity" type="number" step="0.000001" wire:model="minQuantity"
                               class="{{ $input }} w-full">
                    </x-field>

                    <x-field label="Máximo (opcional)" for="maxQuantity" error="maxQuantity">
                        <input id="maxQuantity" type="number" step="0.000001" wire:model="maxQuantity"
                               class="{{ $input }} w-full">
                    </x-field>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancelReorder"
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
