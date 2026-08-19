@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Ajustes de inventario</h2>
            <p class="text-sm text-slate-500">
                Sobrantes y faltantes. Todo ajuste aplicado genera su partida contable.
            </p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <select wire:model.live="statusFilter" class="{{ $input }}">
                <option value="">Todos los estados</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
            @can('create', \App\Domains\Inventory\Models\StockAdjustment::class)
                <a href="{{ route('inventory.adjustments.create') }}" wire:navigate
                   class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Nuevo ajuste
                </a>
            @endcan
        </div>
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Número</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Fecha</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Bodega</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Motivo</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Líneas</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Valor</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($adjustments as $adjustment)
                    <tr role="row" class="hover:bg-slate-50 {{ $adjustment->isVoided() ? 'opacity-60' : '' }}">
                        <td role="cell" data-label="Número" class="px-4 py-1.5 font-mono text-xs whitespace-nowrap">{{ $adjustment->number ?? '—' }}</td>
                        <td role="cell" data-label="Fecha" class="px-4 py-1.5 whitespace-nowrap">{{ $adjustment->date->format('d/m/Y') }}</td>
                        <td role="cell" data-label="Bodega" class="px-4 py-1.5 text-slate-600">{{ $adjustment->warehouse->code }}</td>
                        <td role="cell" data-label="Motivo" class="px-4 py-1.5">{{ $adjustment->reason->label() }}</td>
                        <td role="cell" data-label="Líneas" class="px-4 py-1.5 text-right font-mono text-slate-500">{{ $adjustment->items_count }}</td>
                        <td role="cell" data-label="Valor" class="px-4 py-1.5 text-right font-mono {{ $adjustment->valueAmount()->isNegative() ? 'text-red-600' : '' }}">
                            {{ $adjustment->valueAmount()->format() }}
                        </td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $adjustment->status->badgeClasses() }}">
                                {{ $adjustment->status->label() }}
                            </span>
                        </td>
                        <td role="cell" data-label="Acciones" class="px-4 py-1.5 text-right whitespace-nowrap">
                            <a href="{{ route('inventory.adjustments.show', $adjustment->id) }}" wire:navigate
                               class="text-xs font-medium text-slate-700 underline hover:text-slate-900">Ver</a>
                            @can('update', $adjustment)
                                <a href="{{ route('inventory.adjustments.edit', $adjustment) }}" wire:navigate
                                   class="ml-2 text-xs text-slate-600 underline hover:text-slate-900">Editar</a>
                            @endcan
                            @can('post', $adjustment)
                                <button type="button" wire:click="post({{ $adjustment->id }})"
                                        class="ml-2 text-xs text-emerald-700 underline hover:text-emerald-900">Aplicar</button>
                            @endcan
                            @can('delete', $adjustment)
                                <button type="button" wire:click="delete({{ $adjustment->id }})"
                                        wire:confirm="¿Eliminar este borrador?"
                                        class="ml-2 text-xs text-red-600 underline hover:text-red-800">Eliminar</button>
                            @endcan
                            @can('void', $adjustment)
                                <button type="button" wire:click="confirmVoid({{ $adjustment->id }})"
                                        class="ml-2 text-xs text-red-600 underline hover:text-red-800">Anular</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="8" class="px-4 py-8 text-center text-slate-500">
                            No hay ajustes registrados.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $adjustments->links() }}</div>

    @if ($voidingId)
        <x-modal title="Anular ajuste" onClose="cancelVoid">
            <form wire:submit="void">
                <div class="space-y-3 p-5">
                    <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        Las existencias volverán a como estaban y la partida contable se revertirá.
                        El documento conserva su número y sus líneas.
                    </p>

                    <x-field label="Motivo" for="voidReason" error="voidReason">
                        <textarea id="voidReason" wire:model="voidReason" rows="3"
                                  class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none"></textarea>
                    </x-field>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancelVoid"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Anular
                    </button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
