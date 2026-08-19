@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Conciliación bancaria</h2>
            <p class="text-sm text-slate-500">Compara el extracto del banco con el libro.</p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <select wire:model.live="bankAccountId" class="{{ $input }}">
                <option value="">Todas las cuentas</option>
                @foreach ($bankAccounts as $option)
                    <option value="{{ $option->id }}">{{ $option->label() }}</option>
                @endforeach
            </select>

            @can('create', \App\Domains\Treasury\Models\BankReconciliation::class)
                <button type="button" wire:click="create"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Nueva conciliación
                </button>
            @endcan
        </div>
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Corte</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Cuenta</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Extracto</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Libros</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Diferencia</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($reconciliations as $reconciliation)
                    <tr role="row" class="hover:bg-slate-50">
                        <td role="cell" data-label="Corte" class="px-4 py-1.5 whitespace-nowrap">
                            {{ $reconciliation->cutoff_date->format('d/m/Y') }}
                        </td>
                        <td role="cell" data-label="Cuenta" class="px-4 py-1.5">{{ $reconciliation->bankAccount->label() }}</td>
                        <td role="cell" data-label="Extracto" class="px-4 py-1.5 text-right font-mono">
                            {{ $reconciliation->statementBalance()->format() }}
                        </td>
                        <td role="cell" data-label="Libros" class="px-4 py-1.5 text-right font-mono">
                            {{ $reconciliation->bookBalance()->format() }}
                        </td>
                        <td role="cell" data-label="Diferencia"
                            class="px-4 py-1.5 text-right font-mono {{ $reconciliation->isBalanced() ? 'text-slate-400' : 'font-semibold text-amber-700' }}">
                            {{ $reconciliation->differenceAmount()->format() }}
                        </td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $reconciliation->status->badgeClasses() }}">
                                {{ $reconciliation->status->label() }}
                            </span>
                        </td>
                        <td role="cell" class="px-4 py-1.5 text-right whitespace-nowrap">
                            <a href="{{ route('treasury.reconciliations.show', $reconciliation) }}" wire:navigate
                               class="text-xs text-slate-600 underline hover:text-slate-900">Abrir</a>
                            @can('delete', $reconciliation)
                                <button type="button" wire:click="delete({{ $reconciliation->id }})"
                                        wire:confirm="¿Eliminar esta conciliación? Las partidas volverán a quedar disponibles."
                                        class="ml-2 text-xs text-red-600 underline hover:text-red-800">Eliminar</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="7" class="px-4 py-8 text-center text-slate-500">
                            Todavía no hay conciliaciones.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $reconciliations->links() }}</div>

    @if ($showForm)
        <x-modal title="Nueva conciliación">
            <form wire:submit="save">
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Cuenta bancaria" for="bankAccountId" error="bankAccountId" class="sm:col-span-2">
                        <select id="bankAccountId" wire:model="bankAccountId" class="{{ $input }} w-full">
                            <option value="">Selecciona…</option>
                            @foreach ($bankAccounts as $option)
                                <option value="{{ $option->id }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Fecha de corte" for="cutoff_date" error="cutoff_date">
                        <input id="cutoff_date" type="date" wire:model="cutoff_date" class="{{ $input }} w-full">
                    </x-field>

                    <x-field label="Saldo del extracto" for="statement_balance" error="statement_balance"
                             hint="El saldo final que trae el estado de cuenta del banco.">
                        <input id="statement_balance" type="text" inputmode="decimal"
                               wire:model="statement_balance" class="{{ $input }} w-full text-right font-mono">
                    </x-field>

                    <x-field label="Notas" for="notes" error="notes" class="sm:col-span-2">
                        <textarea id="notes" wire:model="notes" rows="2" class="{{ $input }} w-full"></textarea>
                    </x-field>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="$set('showForm', false)"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Empezar
                    </button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
