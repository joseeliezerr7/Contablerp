@php
    $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none';
    $cell = 'w-full rounded border border-slate-200 px-2 py-1 text-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none';
@endphp

<div wire:keydown.window.alt.n.prevent="addLine">
    <x-flash />

    <div class="mb-4 flex items-center justify-between">
        <div>
            <h2 class="text-lg font-semibold">{{ $entryId ? 'Editar partida' : 'Nueva partida' }}</h2>
            <p class="text-sm text-slate-500">
                <kbd class="rounded border border-slate-300 px-1 text-xs">Alt</kbd> +
                <kbd class="rounded border border-slate-300 px-1 text-xs">N</kbd> agrega línea.
            </p>
        </div>
        <a href="{{ route('journal.index') }}" wire:navigate
           class="text-sm text-slate-600 underline hover:text-slate-900">Volver al libro diario</a>
    </div>

    @error('lines')
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
            {{ $message }}
        </div>
    @enderror

    <form wire:submit="saveAndPost" class="space-y-4">
        <div class="grid gap-4 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-4">
            <x-field label="Fecha" for="date" error="date">
                <input id="date" type="date" wire:model="date" class="{{ $input }}">
            </x-field>

            <x-field label="Tipo" for="type" error="type">
                <select id="type" wire:model="type" class="{{ $input }}">
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field label="Referencia" for="reference" error="reference">
                <input id="reference" type="text" wire:model="reference" class="{{ $input }}">
            </x-field>

            <x-field label="Sucursal" for="branch_id" error="branch_id">
                <select id="branch_id" wire:model="branch_id" class="{{ $input }}">
                    <option value="">Sin sucursal</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->label() }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field label="Concepto" for="concept" error="concept" class="sm:col-span-4">
                <input id="concept" type="text" wire:model="concept" autofocus class="{{ $input }}">
            </x-field>
        </div>

        {{-- Lista de cuentas para el autocompletado del navegador: el contador
             teclea el código y el navegador completa. --}}
        <datalist id="cuentas">
            @foreach ($accounts as $account)
                <option value="{{ $account->code }}">{{ $account->name }}</option>
            @endforeach
        </datalist>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table class="table-stacked w-full text-sm">
                <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                    <tr role="row">
                        <th role="columnheader" class="w-10 px-3 py-2 font-semibold">#</th>
                        <th role="columnheader" class="w-48 px-3 py-2 font-semibold">Cuenta</th>
                        <th role="columnheader" class="px-3 py-2 font-semibold">Descripción</th>
                        <th role="columnheader" class="w-36 px-3 py-2 text-right font-semibold">Debe</th>
                        <th role="columnheader" class="w-36 px-3 py-2 text-right font-semibold">Haber</th>
                        <th role="columnheader" class="w-10 px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    @foreach ($lines as $index => $line)
                        <tr role="row" wire:key="line-{{ $index }}">
                            <td role="cell" data-label="Línea" class="px-3 py-1 text-xs text-slate-400">{{ $index + 1 }}</td>
                            <td role="cell" data-label="Cuenta" class="px-3 py-1">
                                <input type="text" list="cuentas" wire:model.blur="lines.{{ $index }}.account_code"
                                       placeholder="1.1.01.01" class="{{ $cell }} font-mono">
                                @php
                                    $matched = $accounts->firstWhere('code', trim($line['account_code'] ?? ''));
                                @endphp
                                @if ($matched)
                                    <p class="mt-0.5 truncate text-[11px] text-slate-500">{{ $matched->name }}</p>
                                @elseif (trim($line['account_code'] ?? '') !== '')
                                    <p class="mt-0.5 text-[11px] text-red-600">Cuenta no encontrada</p>
                                @endif
                            </td>
                            <td role="cell" data-label="Descripción" class="px-3 py-1">
                                <input type="text" wire:model="lines.{{ $index }}.description" class="{{ $cell }}">
                            </td>
                            <td role="cell" data-label="Debe" class="px-3 py-1">
                                <input type="text" inputmode="decimal"
                                       wire:model.live.debounce.400ms="lines.{{ $index }}.debit"
                                       class="{{ $cell }} text-right font-mono">
                            </td>
                            <td role="cell" data-label="Haber" class="px-3 py-1">
                                <input type="text" inputmode="decimal"
                                       wire:model.live.debounce.400ms="lines.{{ $index }}.credit"
                                       class="{{ $cell }} text-right font-mono">
                            </td>
                            <td role="cell" class="px-3 py-1 text-center">
                                @if (count($lines) > 2)
                                    <button type="button" wire:click="removeLine({{ $index }})"
                                            class="text-slate-400 hover:text-red-600" title="Quitar línea">&times;</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot role="rowgroup" class="border-t-2 border-slate-300 bg-slate-50">
                    <tr role="row" class="font-semibold">
                        <td role="cell" colspan="3" class="px-3 py-2 text-right">Totales</td>
                        <td role="cell" data-label="Debe" class="px-3 py-2 text-right font-mono">{{ $this->totalDebit()->format() }}</td>
                        <td role="cell" data-label="Haber" class="px-3 py-2 text-right font-mono">{{ $this->totalCredit()->format() }}</td>
                        <td role="cell"></td>
                    </tr>
                    <tr role="row" class="{{ $this->isBalanced() ? 'text-emerald-700' : 'text-red-700' }}">
                        <td role="cell" colspan="3" class="px-3 py-2 text-right text-sm">Diferencia</td>
                        <td role="cell" colspan="2" class="px-3 py-2 text-right font-mono text-sm">
                            {{ $this->difference()->format() }}
                            @if ($this->isBalanced())
                                <span class="ml-1">✓ cuadra</span>
                            @endif
                        </td>
                        <td role="cell"></td>
                    </tr>
                </tfoot>
            </table>

            <div class="border-t border-slate-200 px-3 py-2">
                <button type="button" wire:click="addLine"
                        class="text-sm text-slate-600 underline hover:text-slate-900">
                    + Agregar línea
                </button>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('journal.index') }}" wire:navigate
               class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                Cancelar
            </a>
            <button type="button" wire:click="saveDraft"
                    class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                Guardar borrador
            </button>
            <button type="submit" @disabled(! $this->isBalanced())
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300">
                Guardar y contabilizar
            </button>
        </div>

        @unless ($this->isBalanced())
            <p class="text-right text-xs text-slate-500">
                El botón de contabilizar se habilita cuando el debe y el haber coinciden.
            </p>
        @endunless
    </form>
</div>
