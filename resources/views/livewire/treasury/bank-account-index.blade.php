@php $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Cuentas bancarias</h2>
            <p class="text-sm text-slate-500">
                Saldo en libros: <span class="font-mono font-semibold">{{ $total->format() }}</span>
            </p>
        </div>

        @can('create', \App\Domains\Treasury\Models\BankAccount::class)
            <button type="button" wire:click="create"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                Nueva cuenta
            </button>
        @endcan
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Banco</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Número</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Cuenta contable</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Chequera</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Saldo en libros</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($accounts as $bankAccount)
                    <tr role="row" class="hover:bg-slate-50 {{ $bankAccount->is_active ? '' : 'opacity-50' }}">
                        <td role="cell" data-label="Banco" class="px-4 py-1.5 font-medium">
                            {{ $bankAccount->bank_name }}
                            @if ($bankAccount->alias)
                                <span class="block text-xs text-slate-500">{{ $bankAccount->alias }}</span>
                            @endif
                        </td>
                        <td role="cell" data-label="Número" class="px-4 py-1.5 font-mono text-xs">{{ $bankAccount->number }}</td>
                        <td role="cell" data-label="Cuenta contable" class="px-4 py-1.5 text-slate-600">
                            <span class="font-mono text-xs">{{ $bankAccount->account->code }}</span>
                            {{ $bankAccount->account->name }}
                        </td>
                        <td role="cell" data-label="Chequera" class="px-4 py-1.5 font-mono text-xs text-slate-600">
                            {{ $bankAccount->next_check_number ? 'Siguiente: '.$bankAccount->next_check_number : '—' }}
                        </td>
                        <td role="cell" data-label="Saldo en libros" class="px-4 py-1.5 text-right font-mono font-semibold">
                            {{ $bankAccount->getAttribute('book_balance')->format() }}
                        </td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            @if ($bankAccount->is_active)
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Activa</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Inactiva</span>
                            @endif
                        </td>
                        <td role="cell" class="px-4 py-1.5 text-right whitespace-nowrap">
                            @can('treasury.reconciliation.manage')
                                <a href="{{ route('treasury.reconciliations.index', ['cuenta' => $bankAccount->id]) }}" wire:navigate
                                   class="text-xs text-slate-600 underline hover:text-slate-900">Conciliar</a>
                            @endcan
                            @can('update', $bankAccount)
                                <button type="button" wire:click="edit({{ $bankAccount->id }})"
                                        class="ml-2 text-xs text-slate-600 underline hover:text-slate-900">Editar</button>
                            @endcan
                            @can('delete', $bankAccount)
                                <button type="button" wire:click="delete({{ $bankAccount->id }})"
                                        wire:confirm="¿Eliminar la cuenta {{ $bankAccount->label() }}?"
                                        class="ml-2 text-xs text-red-600 underline hover:text-red-800">Eliminar</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="7" class="px-4 py-8 text-center text-slate-500">
                            Todavía no hay cuentas bancarias registradas.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar cuenta bancaria' : 'Nueva cuenta bancaria'" onClose="closeForm">
            <form wire:submit="save">
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Cuenta contable" for="account_id" error="account_id" class="sm:col-span-2"
                             hint="Solo cuentas marcadas como efectivo. Cada cuenta bancaria necesita la suya para poder conciliarse por separado.">
                        <select id="account_id" wire:model="account_id" class="{{ $input }}">
                            <option value="">Selecciona…</option>
                            @foreach ($cashAccounts as $option)
                                <option value="{{ $option->id }}">{{ $option->code }} — {{ $option->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Banco" for="bank_name" error="bank_name">
                        <input id="bank_name" type="text" wire:model="bank_name" class="{{ $input }}">
                    </x-field>

                    <x-field label="Número de cuenta" for="number" error="number">
                        <input id="number" type="text" wire:model="number" class="{{ $input }} font-mono">
                    </x-field>

                    <x-field label="Alias" for="alias" error="alias" hint="Cómo la llaman en la empresa.">
                        <input id="alias" type="text" wire:model="alias" class="{{ $input }}">
                    </x-field>

                    <x-field label="Tipo" for="type" error="type">
                        <select id="type" wire:model="type" class="{{ $input }}">
                            <option value="checking">Cuenta de cheques</option>
                            <option value="savings">Cuenta de ahorro</option>
                        </select>
                    </x-field>

                    <x-field label="Siguiente número de cheque" for="next_check_number" error="next_check_number"
                             hint="Déjalo vacío si la cuenta no gira cheques.">
                        <input id="next_check_number" type="number" wire:model="next_check_number" class="{{ $input }} font-mono">
                    </x-field>

                    <x-field label="Estado" for="is_active" error="is_active">
                        <label class="flex items-center gap-2 text-sm">
                            <input id="is_active" type="checkbox" wire:model="is_active" class="rounded border-slate-300">
                            Activa
                        </label>
                    </x-field>

                    <x-field label="Notas" for="notes" error="notes" class="sm:col-span-2">
                        <textarea id="notes" wire:model="notes" rows="2" class="{{ $input }}"></textarea>
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
</div>
