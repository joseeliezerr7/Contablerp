@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Notas de crédito</h2>
            <p class="text-sm text-slate-500">
                Rebajan una factura que ya circuló, sin borrarla. Llevan su propia autorización del SAR.
            </p>
        </div>

        @can('create', App\Domains\Sales\Models\CreditNote::class)
            <a href="{{ route('credit-notes.create') }}" wire:navigate
               class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                Nueva nota de crédito
            </a>
        @endcan
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-2">
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Número, factura o cliente…" class="{{ $input }} w-72">
        <select wire:model.live="statusFilter" class="{{ $input }}">
            <option value="">Todos los estados</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </select>
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Número</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Fecha</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Cliente</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Factura</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Motivo</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Total</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Acciones</th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($notes as $note)
                    <tr role="row" class="hover:bg-slate-50 {{ $note->isVoided() ? 'text-slate-400' : '' }}">
                        <td role="cell" data-label="Número" class="px-4 py-1.5 font-mono">
                            {{ $note->number ?? '— borrador —' }}
                        </td>
                        <td role="cell" data-label="Fecha" class="px-4 py-1.5 whitespace-nowrap">
                            {{ $note->date->format('d/m/Y') }}
                        </td>
                        <td role="cell" data-label="Cliente" class="px-4 py-1.5">{{ $note->customer->name }}</td>
                        <td role="cell" data-label="Factura" class="px-4 py-1.5 font-mono text-xs">
                            {{ $note->sale->number }}
                        </td>
                        <td role="cell" data-label="Motivo" class="px-4 py-1.5 text-xs">
                            {{ $note->reason->label() }}
                        </td>
                        <td role="cell" data-label="Total" class="px-4 py-1.5 text-right font-mono">
                            {{ $note->totalAmount()->format() }}
                        </td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $note->status->badgeClasses() }}">
                                {{ $note->status->label() }}
                            </span>
                        </td>
                        <td role="cell" data-label="Acciones" class="px-4 py-1.5">
                            <div class="flex flex-wrap justify-end gap-x-3 gap-y-1 text-xs">
                                <a href="{{ route('credit-notes.show', $note->id) }}" wire:navigate
                                   class="font-medium text-slate-700 underline hover:text-slate-900">Ver</a>
                                @if ($note->isDraft())
                                    @can('update', $note)
                                        <a href="{{ route('credit-notes.edit', $note->id) }}" wire:navigate
                                           class="text-slate-600 underline hover:text-slate-900">Editar</a>
                                    @endcan
                                    @can('issue', $note)
                                        <button type="button" wire:click="confirmIssue({{ $note->id }})"
                                                class="font-medium text-emerald-700 underline hover:text-emerald-900">Emitir</button>
                                    @endcan
                                    @can('delete', $note)
                                        <button type="button" wire:click="deleteDraft({{ $note->id }})"
                                                wire:confirm="¿Eliminar el borrador?"
                                                class="text-red-600 underline hover:text-red-800">Eliminar</button>
                                    @endcan
                                @else
                                    @can('print', $note)
                                        <a href="{{ route('credit-notes.print', $note->id) }}"
                                           class="text-slate-600 underline hover:text-slate-900">Imprimir</a>
                                    @endcan
                                    @can('void', $note)
                                        <button type="button" wire:click="confirmVoid({{ $note->id }})"
                                                class="text-red-600 underline hover:text-red-800">Anular</button>
                                    @endcan
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="8" class="px-4 py-8 text-center text-slate-500">
                            No hay notas de crédito.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $notes->links() }}</div>

    @if ($issuing)
        <x-modal title="Emitir la nota de crédito" onClose="cancelAction">
            <div class="space-y-3 p-5 text-sm">
                @error('issuing')
                    <p class="rounded-md bg-red-50 px-3 py-2 text-red-800">{{ $message }}</p>
                @enderror

                <p>
                    Se consume un correlativo de la autorización del SAR, se contabiliza la devolución
                    y se rebaja el saldo del cliente. <strong>La emisión no se deshace</strong>: para
                    revertirla hay que anular la nota, que también queda registrada.
                </p>
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                <button type="button" wire:click="cancelAction"
                        class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                    Cancelar
                </button>
                <button type="button" wire:click="issue"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Emitir
                </button>
            </div>
        </x-modal>
    @endif

    @if ($voiding)
        <x-modal title="Anular la nota de crédito" onClose="cancelAction">
            <form wire:submit="void">
                <div class="space-y-3 p-5">
                    <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        Se revierte la partida, vuelve el saldo a la cuenta por cobrar y —si hubo
                        devolución— la mercadería sale otra vez. El documento y su número se conservan.
                    </p>

                    <x-field label="Motivo" for="voidReason" error="voidReason">
                        <textarea id="voidReason" wire:model="voidReason" rows="3" class="{{ $input }} w-full"></textarea>
                    </x-field>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancelAction"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                        Anular
                    </button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
