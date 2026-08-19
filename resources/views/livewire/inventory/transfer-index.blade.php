@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Traslados entre bodegas</h2>
            <p class="text-sm text-slate-500">
                La mercadería cambia de bodega con su costo. No generan partida contable.
            </p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <select wire:model.live="statusFilter" class="{{ $input }}">
                <option value="">Todos los estados</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
            @can('create', \App\Domains\Inventory\Models\StockTransfer::class)
                <a href="{{ route('inventory.transfers.create') }}" wire:navigate
                   class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Nuevo traslado
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
                    <th role="columnheader" class="px-4 py-2 font-semibold">Desde</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Hacia</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Líneas</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Valor</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($transfers as $transfer)
                    <tr role="row" class="hover:bg-slate-50 {{ $transfer->isVoided() ? 'opacity-60' : '' }}">
                        <td role="cell" data-label="Número" class="px-4 py-1.5 font-mono text-xs whitespace-nowrap">{{ $transfer->number ?? '—' }}</td>
                        <td role="cell" data-label="Fecha" class="px-4 py-1.5 whitespace-nowrap">{{ $transfer->date->format('d/m/Y') }}</td>
                        <td role="cell" data-label="Desde" class="px-4 py-1.5 text-slate-600">{{ $transfer->fromWarehouse->code }}</td>
                        <td role="cell" data-label="Hacia" class="px-4 py-1.5 text-slate-600">{{ $transfer->toWarehouse->code }}</td>
                        <td role="cell" data-label="Líneas" class="px-4 py-1.5 text-right font-mono text-slate-500">{{ $transfer->items_count }}</td>
                        <td role="cell" data-label="Valor" class="px-4 py-1.5 text-right font-mono">{{ $transfer->valueAmount()->format() }}</td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $transfer->status->badgeClasses() }}">
                                {{ $transfer->status->label() }}
                            </span>
                        </td>
                        <td role="cell" data-label="Acciones" class="px-4 py-1.5 text-right whitespace-nowrap">
                            <a href="{{ route('inventory.transfers.show', $transfer->id) }}" wire:navigate
                               class="text-xs font-medium text-slate-700 underline hover:text-slate-900">Ver</a>
                            @can('update', $transfer)
                                <a href="{{ route('inventory.transfers.edit', $transfer) }}" wire:navigate
                                   class="ml-2 text-xs text-slate-600 underline hover:text-slate-900">Editar</a>
                            @endcan
                            @can('post', $transfer)
                                <button type="button" wire:click="post({{ $transfer->id }})"
                                        class="ml-2 text-xs text-emerald-700 underline hover:text-emerald-900">Aplicar</button>
                            @endcan
                            @can('delete', $transfer)
                                <button type="button" wire:click="delete({{ $transfer->id }})"
                                        wire:confirm="¿Eliminar este borrador?"
                                        class="ml-2 text-xs text-red-600 underline hover:text-red-800">Eliminar</button>
                            @endcan
                            @can('void', $transfer)
                                <button type="button" wire:click="confirmVoid({{ $transfer->id }})"
                                        class="ml-2 text-xs text-red-600 underline hover:text-red-800">Anular</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="8" class="px-4 py-8 text-center text-slate-500">
                            No hay traslados registrados.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $transfers->links() }}</div>

    @if ($voidingId)
        <x-modal title="Anular traslado" onClose="cancelVoid">
            <form wire:submit="void">
                <div class="space-y-3 p-5">
                    <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        La mercadería volverá a la bodega de origen. El documento conserva su
                        número y sus líneas.
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
