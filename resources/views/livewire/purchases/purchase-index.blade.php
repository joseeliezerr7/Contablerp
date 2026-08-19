@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Compras</h2>
            <p class="text-sm text-slate-500">
                Recibidas en el filtro actual: <span class="font-mono font-semibold">{{ $receivedTotal->format() }}</span>
            </p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Número, factura o proveedor…"
                   class="{{ $input }} w-56">
            <input type="date" wire:model.live="from" class="{{ $input }}">
            <input type="date" wire:model.live="to" class="{{ $input }}">
            <select wire:model.live="statusFilter" class="{{ $input }}">
                <option value="">Todos los estados</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
            @can('create', \App\Domains\Purchases\Models\Purchase::class)
                <a href="{{ route('purchases.create') }}" wire:navigate
                   class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Nueva compra
                </a>
            @endcan
        </div>
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Número</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Factura</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Fecha</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Proveedor</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Total</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Saldo</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($purchases as $purchase)
                    <tr role="row" class="hover:bg-slate-50 {{ $purchase->isVoided() ? 'opacity-60' : '' }}">
                        <td role="cell" data-label="Número" class="px-4 py-1.5 font-mono text-xs whitespace-nowrap">{{ $purchase->number ?? '—' }}</td>
                        <td role="cell" data-label="Factura" class="px-4 py-1.5 font-mono text-xs">{{ $purchase->supplier_invoice_number }}</td>
                        <td role="cell" data-label="Fecha" class="px-4 py-1.5 whitespace-nowrap">{{ $purchase->date->format('d/m/Y') }}</td>
                        <td role="cell" data-label="Proveedor" class="px-4 py-1.5">{{ $purchase->supplier->name }}</td>
                        <td role="cell" data-label="Total" class="px-4 py-1.5 text-right font-mono">{{ $purchase->totalAmount()->format() }}</td>
                        <td role="cell" data-label="Saldo" class="px-4 py-1.5 text-right font-mono">
                            {{ $purchase->payable?->balanceAmount()->format() ?? '—' }}
                        </td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $purchase->status->badgeClasses() }}">
                                {{ $purchase->status->label() }}
                            </span>
                        </td>
                        <td role="cell" data-label="Acciones" class="px-4 py-1.5 text-right whitespace-nowrap">
                            <a href="{{ route('purchases.show', $purchase->id) }}" wire:navigate
                               class="text-xs font-medium text-slate-700 underline hover:text-slate-900">Ver</a>
                            @can('update', $purchase)
                                <a href="{{ route('purchases.edit', $purchase) }}" wire:navigate
                                   class="ml-2 text-xs text-slate-600 underline hover:text-slate-900">Editar</a>
                            @endcan
                            @can('receive', $purchase)
                                <button type="button" wire:click="receive({{ $purchase->id }})"
                                        class="ml-2 text-xs text-emerald-700 underline hover:text-emerald-900">Recibir</button>
                            @endcan
                            @can('delete', $purchase)
                                <button type="button" wire:click="delete({{ $purchase->id }})"
                                        wire:confirm="¿Eliminar este borrador?"
                                        class="ml-2 text-xs text-red-600 underline hover:text-red-800">Eliminar</button>
                            @endcan
                            @can('void', $purchase)
                                <button type="button" wire:click="confirmVoid({{ $purchase->id }})"
                                        class="ml-2 text-xs text-red-600 underline hover:text-red-800">Anular</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="8" class="px-4 py-8 text-center text-slate-500">
                            No hay compras que coincidan con el filtro.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $purchases->links() }}</div>

    @if ($voidingId)
        <x-modal title="Anular compra" onClose="cancelVoid">
            <form wire:submit="void">
                <div class="space-y-3 p-5">
                    <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        La compra conservará su número y sus líneas, se marcará como anulada y su
                        partida contable se revertirá. La cuenta por pagar quedará cancelada y el
                        número de factura del proveedor volverá a quedar disponible.
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
