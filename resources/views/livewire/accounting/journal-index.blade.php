@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Libro diario</h2>
            <p class="text-sm text-slate-500">Partidas de la empresa activa.</p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Folio, concepto o referencia…"
                   class="{{ $input }} w-56">
            <input type="date" wire:model.live="from" class="{{ $input }}">
            <input type="date" wire:model.live="to" class="{{ $input }}">
            <select wire:model.live="statusFilter" class="{{ $input }}">
                <option value="">Todos los estados</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
            @can('create', \App\Domains\Accounting\Models\JournalEntry::class)
                <a href="{{ route('journal.create') }}" wire:navigate
                   class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Nueva partida
                </a>
            @endcan
        </div>
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Folio</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Fecha</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Concepto</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Tipo</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Debe</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Haber</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($entries as $entry)
                    <tr role="row" class="hover:bg-slate-50 {{ $entry->isVoided() ? 'opacity-60' : '' }}">
                        <td role="cell" data-label="Folio" class="px-4 py-1.5 font-mono text-xs whitespace-nowrap">
                            {{ $entry->number ?? '—' }}
                        </td>
                        <td role="cell" data-label="Fecha" class="px-4 py-1.5 whitespace-nowrap">{{ $entry->date->format('d/m/Y') }}</td>
                        <td role="cell" data-label="Concepto" class="px-4 py-1.5">
                            {{ $entry->concept }}
                            @if ($entry->reference)
                                <span class="text-xs text-slate-500">· {{ $entry->reference }}</span>
                            @endif
                        </td>
                        <td role="cell" data-label="Tipo" class="px-4 py-1.5 text-slate-600">{{ $entry->type->label() }}</td>
                        <td role="cell" data-label="Debe" class="px-4 py-1.5 text-right font-mono">{{ $entry->totalDebit()->format() }}</td>
                        <td role="cell" data-label="Haber" class="px-4 py-1.5 text-right font-mono">{{ $entry->totalCredit()->format() }}</td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $entry->status->badgeClasses() }}">
                                {{ $entry->status->label() }}
                            </span>
                        </td>
                        <td role="cell" class="px-4 py-1.5 text-right whitespace-nowrap">
                            @can('update', $entry)
                                <a href="{{ route('journal.edit', $entry) }}" wire:navigate
                                   class="text-xs text-slate-600 underline hover:text-slate-900">Editar</a>
                            @endcan
                            @can('post', $entry)
                                <button type="button" wire:click="post({{ $entry->id }})"
                                        class="ml-2 text-xs text-emerald-700 underline hover:text-emerald-900">
                                    Contabilizar
                                </button>
                            @endcan
                            @can('delete', $entry)
                                <button type="button" wire:click="delete({{ $entry->id }})"
                                        wire:confirm="¿Eliminar este borrador?"
                                        class="ml-2 text-xs text-red-600 underline hover:text-red-800">
                                    Eliminar
                                </button>
                            @endcan
                            @can('void', $entry)
                                <button type="button" wire:click="confirmVoid({{ $entry->id }})"
                                        class="ml-2 text-xs text-red-600 underline hover:text-red-800">
                                    Anular
                                </button>
                            @endcan
                            @can('reverse', $entry)
                                <button type="button" wire:click="confirmReverse({{ $entry->id }})"
                                        class="ml-2 text-xs text-amber-700 underline hover:text-amber-900">
                                    Revertir
                                </button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="8" class="px-4 py-8 text-center text-slate-500">
                            No hay partidas que coincidan con el filtro.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $entries->links() }}</div>

    @if ($actionEntryId)
        <x-modal :title="$actionType === 'void' ? 'Anular partida' : 'Revertir partida'" onClose="cancelAction">
            <form wire:submit="runAction">
                <div class="space-y-4 p-5">
                    <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        @if ($actionType === 'void')
                            La partida se marcará como anulada y su efecto se restará de los saldos.
                            El registro y sus líneas se conservan en el historial.
                        @else
                            Se generará una partida nueva con los importes al lado contrario.
                            La original permanece intacta.
                        @endif
                    </p>

                    @if ($actionType === 'reverse')
                        <x-field label="Fecha de la reversión" for="actionDate" error="actionDate">
                            <input id="actionDate" type="date" wire:model="actionDate" class="{{ $input }} w-full">
                        </x-field>
                    @endif

                    <x-field label="Motivo" for="actionReason" error="actionReason"
                             hint="Queda registrado en la bitácora de auditoría.">
                        <textarea id="actionReason" wire:model="actionReason" rows="3"
                                  class="{{ $input }} w-full"></textarea>
                    </x-field>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancelAction"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Confirmar
                    </button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
