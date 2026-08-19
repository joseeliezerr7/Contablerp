@php
    $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none';
    $cell = 'w-full rounded-md border border-slate-300 px-2 py-1 text-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none';
    $trim = fn (?string $n) => $n === null ? null : (rtrim(rtrim($n, '0'), '.') ?: '0');
@endphp

<div>
    <x-flash />

    <div class="mb-4 flex items-center justify-between">
        <h2 class="text-lg font-semibold">
            {{ $adjustmentId ? 'Editar ajuste' : 'Nuevo ajuste de inventario' }}
        </h2>
        <a href="{{ route('inventory.adjustments.index') }}" wire:navigate
           class="text-sm text-slate-600 underline hover:text-slate-900">Volver</a>
    </div>

    @error('lines')
        <div class="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>
    @enderror

    <div class="mb-4 rounded-xl border border-slate-200 bg-white p-4">
        <div class="grid gap-4 md:grid-cols-3">
            <x-field label="Fecha" for="date" error="date">
                <input id="date" type="date" wire:model="date" class="{{ $input }}">
            </x-field>

            <x-field label="Sucursal" for="branch_id" error="branch_id">
                <select id="branch_id" wire:model="branch_id" class="{{ $input }}">
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->label() }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field label="Bodega" for="warehouse_id" error="warehouse_id">
                <select id="warehouse_id" wire:model.live="warehouse_id" class="{{ $input }}">
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->label() }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field label="Motivo" for="reason" error="reason">
                <select id="reason" wire:model="reason" class="{{ $input }}">
                    @foreach ($reasons as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field label="Cuenta de la diferencia" for="adjustment_account_id" error="adjustment_account_id">
                <select id="adjustment_account_id" wire:model="adjustment_account_id" class="{{ $input }}">
                    <option value="">Ajustes de inventario (predeterminada)</option>
                    @foreach ($adjustmentAccounts as $account)
                        <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field label="Notas" for="notes" error="notes">
                <input id="notes" type="text" wire:model="notes" class="{{ $input }}">
            </x-field>
        </div>
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-3 py-2 font-semibold">Producto</th>
                    <th role="columnheader" class="w-32 px-3 py-2 text-right font-semibold">En sistema</th>
                    <th role="columnheader" class="w-32 px-3 py-2 text-right font-semibold">Diferencia</th>
                    <th role="columnheader" class="w-32 px-3 py-2 text-right font-semibold">Costo unit.</th>
                    <th role="columnheader" class="w-10 px-3 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @foreach ($lines as $index => $line)
                    <tr role="row">
                        <td role="cell" data-label="Producto" class="px-3 py-1.5">
                            <select wire:model.live="lines.{{ $index }}.product_id" class="{{ $cell }}">
                                <option value="">Elige un producto…</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}">{{ $product->code }} — {{ $product->name }}</option>
                                @endforeach
                            </select>
                            @error("lines.{$index}.product_id")
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </td>
                        <td role="cell" data-label="En sistema" class="px-3 py-1.5 text-right font-mono text-slate-500">
                            {{ $trim($line['on_hand'] ?? null) ?? '—' }}
                        </td>
                        <td role="cell" data-label="Diferencia" class="px-3 py-1.5">
                            <input type="number" step="0.000001" wire:model="lines.{{ $index }}.quantity"
                                   class="{{ $cell }} text-right font-mono">
                            @error("lines.{$index}.quantity")
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </td>
                        <td role="cell" data-label="Costo unit." class="px-3 py-1.5">
                            <input type="number" step="0.01" wire:model="lines.{{ $index }}.unit_cost"
                                   class="{{ $cell }} text-right font-mono" placeholder="Promedio">
                        </td>
                        <td role="cell" class="px-3 py-1.5 text-center">
                            <button type="button" wire:click="removeLine({{ $index }})"
                                    class="text-xs text-red-600 hover:text-red-800">✕</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="border-t border-slate-200 px-3 py-2">
            <button type="button" wire:click="addLine"
                    class="text-sm text-slate-600 underline hover:text-slate-900">
                + Agregar línea
            </button>
        </div>
    </div>

    <p class="mt-3 text-xs text-slate-500">
        La cantidad va con signo: positiva si sobra mercadería, negativa si falta. El costo
        unitario solo hace falta para las entradas de un producto sin existencia; en los demás
        casos lo pone el promedio de la bodega.
    </p>

    <div class="mt-4 flex justify-end gap-2">
        <button type="button" wire:click="saveDraft"
                class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
            Guardar borrador
        </button>
        {{-- Permiso suelto y no la policy: el documento todavía no existe, y la
             policy de `post` exige que esté en borrador. --}}
        @can('inventory.adjustments.post')
            <button type="button" wire:click="saveAndPost"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                Guardar y aplicar
            </button>
        @endcan
    </div>
</div>
