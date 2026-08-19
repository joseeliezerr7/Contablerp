@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Facturas de venta</h2>
            <p class="text-sm text-slate-500">
                Emitidas en el filtro actual: <span class="font-mono font-semibold">{{ $issuedTotal->format() }}</span>
            </p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Número, referencia o cliente…"
                   class="{{ $input }} w-56">
            <input type="date" wire:model.live="from" class="{{ $input }}">
            <input type="date" wire:model.live="to" class="{{ $input }}">
            <select wire:model.live="statusFilter" class="{{ $input }}">
                <option value="">Todos los estados</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
            @can('create', \App\Domains\Sales\Models\Sale::class)
                <a href="{{ route('sales.create') }}" wire:navigate
                   class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Nueva factura
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
                    <th role="columnheader" class="px-4 py-2 font-semibold">Cliente</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Condición</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Total</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Saldo</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($sales as $sale)
                    <tr role="row" class="hover:bg-slate-50 {{ $sale->isVoided() ? 'opacity-60' : '' }}">
                        <td role="cell" data-label="Número" class="px-4 py-1.5 font-mono text-xs whitespace-nowrap">{{ $sale->number ?? '—' }}</td>
                        <td role="cell" data-label="Fecha" class="px-4 py-1.5 whitespace-nowrap">{{ $sale->date->format('d/m/Y') }}</td>
                        <td role="cell" data-label="Cliente" class="px-4 py-1.5">{{ $sale->customer->name }}</td>
                        <td role="cell" data-label="Condición" class="px-4 py-1.5 text-slate-600">
                            {{ $sale->payment_condition->label() }}
                            @if ($sale->isOnCredit() && $sale->due_date)
                                <span class="text-xs text-slate-500">· vence {{ $sale->due_date->format('d/m/Y') }}</span>
                            @endif
                        </td>
                        <td role="cell" data-label="Total" class="px-4 py-1.5 text-right font-mono">{{ $sale->totalAmount()->format() }}</td>
                        <td role="cell" data-label="Saldo" class="px-4 py-1.5 text-right font-mono">
                            {{ $sale->receivable?->balanceAmount()->format() ?? '—' }}
                        </td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $sale->status->badgeClasses() }}">
                                {{ $sale->status->label() }}
                            </span>
                        </td>
                        <td role="cell" data-label="Acciones" class="px-4 py-1.5">
                            <div class="flex flex-wrap justify-end gap-x-3 gap-y-1 text-xs">
                                {{-- Primero «Ver»: es lo que se busca casi
                                     siempre, y para una factura emitida era lo
                                     único que no se podía hacer. --}}
                                <a href="{{ route('sales.show', $sale->id) }}" wire:navigate
                                   class="font-medium text-slate-700 underline hover:text-slate-900">Ver</a>
                                @can('update', $sale)
                                    <a href="{{ route('sales.edit', $sale) }}" wire:navigate
                                       class="text-slate-600 underline hover:text-slate-900">Editar</a>
                                @endcan
                                @can('issue', $sale)
                                    <button type="button" wire:click="issue({{ $sale->id }})"
                                            class="text-emerald-700 underline hover:text-emerald-900">Emitir</button>
                                @endcan
                                @can('delete', $sale)
                                    <button type="button" wire:click="delete({{ $sale->id }})"
                                            wire:confirm="¿Eliminar este borrador?"
                                            class="text-red-600 underline hover:text-red-800">Eliminar</button>
                                @endcan
                                @can('print', $sale)
                                    <a href="{{ route('sales.print', $sale->id) }}"
                                       class="text-slate-600 underline hover:text-slate-900">Imprimir</a>
                                @endcan
                                @can('void', $sale)
                                    <button type="button" wire:click="confirmVoid({{ $sale->id }})"
                                            class="text-red-600 underline hover:text-red-800">Anular</button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="8" class="px-4 py-8 text-center text-slate-500">
                            No hay facturas que coincidan con el filtro.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $sales->links() }}</div>

    @if ($voidingId)
        <x-modal title="Anular factura" onClose="cancelVoid">
            <form wire:submit="void">
                <div class="space-y-3 p-5">
                    <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        La factura conservará su número y sus líneas, se marcará como anulada y
                        su partida contable se revertirá. La cuenta por cobrar quedará cancelada.
                    </p>

                    <x-field label="Motivo" for="voidReason" error="voidReason"
                             hint="Queda registrado en la bitácora de auditoría.">
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
