@php $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Activos fijos</h2>
            <p class="text-sm text-slate-500">
                Costo <span class="font-mono font-semibold">{{ $totalCost->format() }}</span> ·
                valor en libros <span class="font-mono font-semibold">{{ $totalBookValue->format() }}</span>
            </p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Código, nombre o serie…"
                   class="{{ $input }} w-56">
            <select wire:model.live="statusFilter" class="{{ $input }} w-auto">
                <option value="">Todos los estados</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
            @can('create', \App\Domains\Assets\Models\FixedAsset::class)
                <button type="button" wire:click="create"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Nuevo activo
                </button>
            @endcan
        </div>
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Código</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Activo</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Categoría</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Compra</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Costo</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Depreciado</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">En libros</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($assets as $asset)
                    <tr role="row" class="hover:bg-slate-50 {{ $asset->isDisposed() ? 'opacity-60' : '' }}">
                        <td role="cell" data-label="Código" class="px-4 py-1.5 font-mono text-xs">{{ $asset->code }}</td>
                        <td role="cell" data-label="Activo" class="px-4 py-1.5">
                            {{ $asset->name }}
                            @if ($asset->serial_number)
                                <span class="block font-mono text-xs text-slate-500">{{ $asset->serial_number }}</span>
                            @endif
                        </td>
                        <td role="cell" data-label="Categoría" class="px-4 py-1.5 text-slate-600">{{ $asset->category->name }}</td>
                        <td role="cell" data-label="Compra" class="px-4 py-1.5 whitespace-nowrap">
                            {{ $asset->acquired_on->format('d/m/Y') }}
                            <span class="block text-xs text-slate-500">{{ $asset->useful_life_months }} meses</span>
                        </td>
                        <td role="cell" data-label="Costo" class="px-4 py-1.5 text-right font-mono">{{ $asset->costAmount()->format() }}</td>
                        <td role="cell" data-label="Depreciado" class="px-4 py-1.5 text-right font-mono text-slate-600">
                            {{ $asset->accumulated()->format() }}
                        </td>
                        <td role="cell" data-label="En libros" class="px-4 py-1.5 text-right font-mono font-semibold">
                            {{ $asset->bookValue()->format() }}
                        </td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $asset->status->badgeClasses() }}">
                                {{ $asset->status->label() }}
                            </span>
                        </td>
                        <td role="cell" data-label="Acciones" class="px-4 py-1.5 text-right whitespace-nowrap">
                            <a href="{{ route('assets.show', $asset->id) }}" wire:navigate
                               class="text-xs font-medium text-slate-700 underline hover:text-slate-900">Ver</a>
                            @can('update', $asset)
                                <button type="button" wire:click="edit({{ $asset->id }})"
                                        class="ml-2 text-xs text-slate-600 underline hover:text-slate-900">Editar</button>
                            @endcan
                            @can('dispose', $asset)
                                <button type="button" wire:click="confirmDispose({{ $asset->id }})"
                                        class="ml-2 text-xs text-amber-700 underline hover:text-amber-900">Dar de baja</button>
                            @endcan
                            @can('delete', $asset)
                                <button type="button" wire:click="delete({{ $asset->id }})"
                                        wire:confirm="¿Eliminar {{ $asset->name }}?"
                                        class="ml-2 text-xs text-red-600 underline hover:text-red-800">Eliminar</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="9" class="px-4 py-8 text-center text-slate-500">
                            Todavía no hay activos fijos registrados.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $assets->links() }}</div>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar activo' : 'Nuevo activo fijo'" onClose="closeForm">
            <form wire:submit="save">
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600 sm:col-span-2">
                        Dar de alta un activo no genera partida contable: la compra ya se registró.
                        Aquí se declara qué hay que depreciar y en cuánto tiempo.
                    </p>

                    <x-field label="Código" for="code" error="code">
                        <input id="code" type="text" wire:model="code" class="{{ $input }} font-mono">
                    </x-field>

                    <x-field label="Categoría" for="fixed_asset_category_id" error="fixed_asset_category_id">
                        <select id="fixed_asset_category_id" wire:model.live="fixed_asset_category_id" class="{{ $input }}">
                            <option value="">Selecciona…</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Nombre" for="name" error="name" class="sm:col-span-2">
                        <input id="name" type="text" wire:model="name" class="{{ $input }}">
                    </x-field>

                    <x-field label="Número de serie" for="serial_number" error="serial_number">
                        <input id="serial_number" type="text" wire:model="serial_number" class="{{ $input }} font-mono">
                    </x-field>

                    <x-field label="Ubicación" for="location" error="location">
                        <input id="location" type="text" wire:model="location" class="{{ $input }}">
                    </x-field>

                    <x-field label="Sucursal" for="branch_id" error="branch_id">
                        <select id="branch_id" wire:model="branch_id" class="{{ $input }}">
                            <option value="">Sin asignar</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Fecha de compra" for="acquired_on" error="acquired_on"
                             hint="La depreciación arranca el mes siguiente.">
                        <input id="acquired_on" type="date" wire:model="acquired_on" class="{{ $input }}">
                    </x-field>

                    <x-field label="Costo" for="cost" error="cost">
                        <input id="cost" type="text" inputmode="decimal" wire:model="cost"
                               class="{{ $input }} text-right font-mono">
                    </x-field>

                    <x-field label="Valor residual" for="salvage_value" error="salvage_value"
                             hint="Lo que valdrá al final. No se deprecia por debajo de esto.">
                        <input id="salvage_value" type="text" inputmode="decimal" wire:model="salvage_value"
                               class="{{ $input }} text-right font-mono">
                    </x-field>

                    <x-field label="Vida útil (meses)" for="useful_life_months" error="useful_life_months">
                        <input id="useful_life_months" type="number" wire:model="useful_life_months"
                               class="{{ $input }} text-right font-mono">
                    </x-field>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeForm"
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

    @if ($disposingId)
        @php $baja = \App\Domains\Assets\Models\FixedAsset::query()->find($disposingId); @endphp
        <x-modal title="Dar de baja el activo" onClose="cancelDispose">
            <form wire:submit="dispose">
                <div class="space-y-3 p-5">
                    <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        Sale del balance el costo ({{ $baja?->costAmount()->format() }}) y su depreciación
                        acumulada ({{ $baja?->accumulated()->format() }}). La diferencia entre lo que se
                        reciba y su valor en libros ({{ $baja?->bookValue()->format() }}) se reconoce como
                        ganancia o pérdida.
                    </p>

                    <x-field label="Fecha de baja" for="disposed_on" error="disposed_on">
                        <input id="disposed_on" type="date" wire:model="disposed_on" class="{{ $input }}">
                    </x-field>

                    <x-field label="Importe recibido" for="disposal_amount" error="disposal_amount"
                             hint="Cero si se descartó sin venderlo.">
                        <input id="disposal_amount" type="text" inputmode="decimal" wire:model="disposal_amount"
                               class="{{ $input }} text-right font-mono">
                    </x-field>

                    <x-field label="Motivo" for="disposal_reason" error="disposal_reason">
                        <textarea id="disposal_reason" wire:model="disposal_reason" rows="3" class="{{ $input }}"></textarea>
                    </x-field>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancelDispose"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Dar de baja
                    </button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
