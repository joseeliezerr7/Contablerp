<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">{{ $reconciliation->label() }}</h2>
            <p class="text-sm text-slate-500">
                {{ $reconciliation->bankAccount->label() }}
                <span class="ml-2 rounded-full px-2 py-0.5 text-xs font-medium {{ $reconciliation->status->badgeClasses() }}">
                    {{ $reconciliation->status->label() }}
                </span>
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('treasury.reconciliations.index') }}" wire:navigate
               class="text-sm text-slate-600 underline hover:text-slate-900">Volver</a>

            @if ($reconciliation->isDraft())
                @can('update', $reconciliation)
                    <button type="button" wire:click="markAll"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Marcar todo
                    </button>
                @endcan
                @can('close', $reconciliation)
                    <button type="button" wire:click="close"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Cerrar conciliación
                    </button>
                @endcan
            @else
                @can('reopen', $reconciliation)
                    <button type="button" wire:click="confirmReopen"
                            class="rounded-md border border-amber-300 px-4 py-2 text-sm font-medium text-amber-800 hover:bg-amber-50">
                        Reabrir
                    </button>
                @endcan
            @endif
        </div>
    </div>

    {{-- Los cuatro números de la conciliación, siempre a la vista. --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-xs tracking-wider text-slate-500 uppercase">Saldo del extracto</p>
            <p class="mt-1 font-mono text-lg font-semibold">{{ $reconciliation->statementBalance()->format() }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-xs tracking-wider text-slate-500 uppercase">+ Depósitos en tránsito</p>
            <p class="mt-1 font-mono text-lg font-semibold text-emerald-700">
                {{ $reconciliation->depositsInTransit()->format() }}
            </p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-xs tracking-wider text-slate-500 uppercase">− Cheques pendientes</p>
            <p class="mt-1 font-mono text-lg font-semibold text-red-700">
                {{ $reconciliation->outstandingChecks()->format() }}
            </p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-xs tracking-wider text-slate-500 uppercase">= Saldo en libros</p>
            <p class="mt-1 font-mono text-lg font-semibold">{{ $reconciliation->bookBalance()->format() }}</p>
        </div>
    </div>

    @if ($reconciliation->isBalanced())
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            ✓ La conciliación cuadra.
        </div>
    @else
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Quedan <span class="font-mono font-semibold">{{ $reconciliation->differenceAmount()->format() }}</span>
            sin explicar. Suele ser una comisión, un interés o una nota del banco que todavía no está en el libro:
            regístrala en el diario y vuelve a marcar.
        </div>
    @endif

    @if ($outstandingChecks->isPositive())
        <p class="mb-4 text-xs text-slate-500">
            Hay <span class="font-mono">{{ $outstandingChecks->format() }}</span> en cheques girados que el banco
            todavía no ha pagado a esta fecha. Sus partidas están en la lista de abajo, sin marcar.
        </p>
    @endif

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">En el extracto</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Fecha</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Folio</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Concepto</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Entrada</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Salida</th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($items as $line)
                    @php $marked = in_array($line->id, $markedIds, true); @endphp
                    <tr role="row" wire:key="line-{{ $line->id }}" class="hover:bg-slate-50 {{ $marked ? 'bg-emerald-50/40' : '' }}">
                        <td role="cell" data-label="En el extracto" class="px-4 py-1.5">
                            <input type="checkbox" @checked($marked)
                                   wire:click="toggle({{ $line->id }})"
                                   @disabled(! $reconciliation->isDraft())
                                   aria-label="Marcar la partida {{ $line->entry->number }} como presente en el extracto"
                                   class="rounded border-slate-300">
                        </td>
                        <td role="cell" data-label="Fecha" class="px-4 py-1.5 whitespace-nowrap">
                            {{ $line->entry->date->format('d/m/Y') }}
                        </td>
                        <td role="cell" data-label="Folio" class="px-4 py-1.5 font-mono text-xs">{{ $line->entry->number }}</td>
                        <td role="cell" data-label="Concepto" class="px-4 py-1.5">
                            {{ $line->description ?: $line->entry->concept }}
                            @if ($line->entry->reference)
                                <span class="block text-xs text-slate-500">{{ $line->entry->reference }}</span>
                            @endif
                        </td>
                        <td role="cell" data-label="Entrada" class="px-4 py-1.5 text-right font-mono text-emerald-700">
                            {{ $line->debitAmount()->isZero() ? '—' : $line->debitAmount()->format() }}
                        </td>
                        <td role="cell" data-label="Salida" class="px-4 py-1.5 text-right font-mono text-red-700">
                            {{ $line->creditAmount()->isZero() ? '—' : $line->creditAmount()->format() }}
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="6" class="px-4 py-8 text-center text-slate-500">
                            No hay partidas de esta cuenta hasta la fecha de corte.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($showReopen)
        <x-modal title="Reabrir conciliación" onClose="cancelReopen">
            <form wire:submit="reopen">
                <div class="space-y-3 p-5">
                    <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        Reabrir permite volver a marcar partidas. Queda registrado en la bitácora
                        junto con el motivo.
                    </p>

                    <x-field label="Motivo" for="voidReason" error="voidReason">
                        <textarea id="voidReason" wire:model="voidReason" rows="3"
                                  class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none"></textarea>
                    </x-field>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancelReopen"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Reabrir
                    </button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
