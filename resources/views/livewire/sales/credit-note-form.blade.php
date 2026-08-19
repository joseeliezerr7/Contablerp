@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4">
        <h2 class="text-lg font-semibold">
            {{ $this->noteId ? 'Editar nota de crédito' : 'Nueva nota de crédito' }}
        </h2>
        <p class="text-sm text-slate-500">
            Se parte de una factura emitida: la nota rebaja lo que ya se facturó, no crea líneas nuevas.
        </p>
    </div>

    <form wire:submit="save" class="space-y-5">
        {{-- Factura de origen --}}
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="flex flex-wrap items-end gap-3">
                <x-field label="Número de factura" for="saleNumber" error="saleNumber"
                         hint="El número fiscal completo, por ejemplo 000-001-01-00000042.">
                    <input id="saleNumber" type="text" wire:model="saleNumber"
                           wire:keydown.enter.prevent="findSale"
                           class="{{ $input }} w-64 font-mono" @disabled($this->noteId !== null)>
                </x-field>

                @unless ($this->noteId)
                    <button type="button" wire:click="findSale"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Buscar
                    </button>
                @endunless
            </div>

            @if ($sale)
                <div class="mt-3 grid gap-2 border-t border-slate-100 pt-3 text-sm sm:grid-cols-3">
                    <div>
                        <span class="block text-xs text-slate-500">Cliente</span>
                        {{ $sale->customer->name }}
                    </div>
                    <div>
                        <span class="block text-xs text-slate-500">Fecha de la factura</span>
                        {{ $sale->date->format('d/m/Y') }}
                    </div>
                    <div>
                        <span class="block text-xs text-slate-500">Total facturado</span>
                        <span class="font-mono">{{ $sale->totalAmount()->format() }}</span>
                    </div>
                </div>
            @endif
        </div>

        @if ($blocked)
            <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ $blocked }}
            </div>
        @endif

        @if ($sale)
            {{-- Motivo --}}
            <div class="grid gap-4 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-3">
                <x-field label="Fecha" for="date" error="date">
                    <input id="date" type="date" wire:model="date" class="{{ $input }} w-full">
                </x-field>

                <x-field label="Motivo" for="reason" error="reason">
                    <select id="reason" wire:model.live="reason" class="{{ $input }} w-full">
                        @foreach ($reasons as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                </x-field>

                <div class="flex items-end">
                    @if (App\Domains\Sales\Enums\CreditNoteReason::from($reason)->movesStock())
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="restocks"
                                   class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                            La mercadería vuelve a la bodega
                        </label>
                    @else
                        <p class="text-xs text-slate-500">
                            Un descuento o una corrección no mueven existencias.
                        </p>
                    @endif
                </div>

                <x-field label="Descripción" for="description" error="description" class="sm:col-span-3"
                         hint="Qué pasó. Queda impreso en la nota y es lo que explica la rebaja ante el fisco.">
                    <textarea id="description" wire:model="description" rows="2" class="{{ $input }} w-full"></textarea>
                </x-field>
            </div>

            {{-- Líneas --}}
            <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table class="table-stacked w-full text-sm">
                    <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                        <tr role="row">
                            <th role="columnheader" class="px-4 py-2 font-semibold">Descripción</th>
                            <th role="columnheader" class="px-4 py-2 text-right font-semibold">Facturado</th>
                            <th role="columnheader" class="px-4 py-2 text-right font-semibold">Precio</th>
                            <th role="columnheader" class="px-4 py-2 text-right font-semibold">A acreditar</th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup" class="divide-y divide-slate-100">
                        @foreach ($lines as $index => $line)
                            <tr role="row">
                                <td role="cell" data-label="Descripción" class="px-4 py-1.5">
                                    {{ $line['description'] }}
                                </td>
                                <td role="cell" data-label="Facturado" class="px-4 py-1.5 text-right font-mono">
                                    {{ rtrim(rtrim($line['sold'], '0'), '.') }}
                                </td>
                                <td role="cell" data-label="Precio" class="px-4 py-1.5 text-right font-mono">
                                    {{ number_format((float) $line['unit_price'], 2, '.', ',') }}
                                </td>
                                <td role="cell" data-label="A acreditar" class="px-4 py-1.5 text-right">
                                    <input type="number" step="0.0001" min="0" max="{{ $line['sold'] }}"
                                           wire:model.live.debounce.400ms="lines.{{ $index }}.quantity"
                                           class="{{ $input }} w-28 text-right font-mono">
                                    @error("lines.$index.quantity")
                                        <span class="block text-xs text-red-600">{{ $message }}</span>
                                    @enderror
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @error('lines') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4">
                <div>
                    <span class="block text-xs tracking-wider text-slate-500 uppercase">Total a acreditar</span>
                    <span class="font-mono text-2xl font-semibold">{{ $total->format() }}</span>
                    <span class="block text-xs text-slate-500">
                        Impuesto incluido, proporcional a lo que se devuelve.
                    </span>
                </div>

                <div class="flex gap-2">
                    <a href="{{ route('credit-notes.index') }}" wire:navigate
                       class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Cancelar
                    </a>
                    <button type="submit"
                            class="rounded-md bg-slate-900 px-5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Guardar borrador
                    </button>
                </div>
            </div>
        @endif
    </form>
</div>
