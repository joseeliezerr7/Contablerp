@php
    $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none';
    $cell = 'w-full rounded border border-slate-200 px-2 py-1 text-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none';
    $totals = $this->totals();
    $customer = $this->customer();
@endphp

<div wire:keydown.window.alt.n.prevent="addLine">
    <x-flash />

    <div class="mb-4 flex items-center justify-between">
        <div>
            <h2 class="text-lg font-semibold">{{ $saleId ? 'Editar factura' : 'Nueva factura' }}</h2>
            <p class="text-sm text-slate-500">
                Escribe el código del producto o pasa el lector de barras.
                <kbd class="rounded border border-slate-300 px-1 text-xs">Alt</kbd> +
                <kbd class="rounded border border-slate-300 px-1 text-xs">N</kbd> agrega línea.
            </p>
        </div>
        <a href="{{ route('sales.index') }}" wire:navigate
           class="text-sm text-slate-600 underline hover:text-slate-900">Volver a facturas</a>
    </div>

    @error('lines')
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">{{ $message }}</div>
    @enderror

    <form wire:submit="saveAndIssue" class="space-y-4">
        <div class="grid gap-4 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-4">
            <x-field label="Cliente" for="customer_id" error="customer_id" class="sm:col-span-2">
                <select id="customer_id" wire:model.live="customer_id" class="{{ $input }}">
                    <option value="">Selecciona…</option>
                    @foreach ($customers as $option)
                        <option value="{{ $option->id }}">{{ $option->code }} — {{ $option->name }}</option>
                    @endforeach
                </select>
                @if ($customer)
                    <p class="mt-1 text-xs text-slate-500">
                        Saldo actual {{ $customer->outstandingBalance()->format() }} ·
                        {{ $customer->hasCredit() ? 'límite '.$customer->creditLimit()->format() : 'solo contado' }}
                    </p>
                @endif
            </x-field>

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

            <x-field label="Bodega que despacha" for="warehouse_id" error="warehouse_id">
                <select id="warehouse_id" wire:model="warehouse_id" class="{{ $input }}">
                    <option value="">Sin bodega (solo servicios)</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->label() }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field label="Condición" for="payment_condition" error="payment_condition">
                <select id="payment_condition" wire:model.live="payment_condition" class="{{ $input }}">
                    <option value="cash">Contado</option>
                    <option value="credit">Crédito</option>
                </select>
            </x-field>

            @if ($payment_condition === 'credit')
                <x-field label="Días de crédito" for="credit_days" error="credit_days">
                    <input id="credit_days" type="number" min="0" wire:model="credit_days"
                           class="{{ $input }} text-right">
                </x-field>
            @else
                <x-field label="Ingresa a" for="deposit_account_id" error="deposit_account_id">
                    <select id="deposit_account_id" wire:model="deposit_account_id" class="{{ $input }}">
                        @foreach ($cashAccounts as $accountOption)
                            <option value="{{ $accountOption->id }}">{{ $accountOption->label() }}</option>
                        @endforeach
                    </select>
                </x-field>
            @endif

            <x-field label="Referencia" for="reference" error="reference">
                <input id="reference" type="text" wire:model="reference" class="{{ $input }}">
            </x-field>

            <x-field label="Notas" for="notes" error="notes">
                <input id="notes" type="text" wire:model="notes" class="{{ $input }}">
            </x-field>
        </div>

        <datalist id="productos">
            @foreach ($products as $option)
                <option value="{{ $option->code }}">{{ $option->name }}</option>
            @endforeach
        </datalist>

        <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="table-stacked w-full text-sm">
                <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                    <tr role="row">
                        <th role="columnheader" class="w-8 px-2 py-2 font-semibold">#</th>
                        <th role="columnheader" class="w-32 px-2 py-2 font-semibold">Código</th>
                        <th role="columnheader" class="px-2 py-2 font-semibold">Descripción</th>
                        <th role="columnheader" class="w-24 px-2 py-2 text-right font-semibold">Cant.</th>
                        <th role="columnheader" class="w-28 px-2 py-2 text-right font-semibold">Precio</th>
                        <th role="columnheader" class="w-20 px-2 py-2 text-right font-semibold">Desc.%</th>
                        <th role="columnheader" class="w-32 px-2 py-2 font-semibold">Impuesto</th>
                        <th role="columnheader" class="w-28 px-2 py-2 text-right font-semibold">Total</th>
                        <th role="columnheader" class="w-8 px-2 py-2"></th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    @foreach ($lines as $index => $line)
                        @php
                            $lineTotal = app(\App\Domains\Taxation\Services\TaxResolver::class)->calculateLine(
                                is_numeric($line['quantity'] ?? null) ? (string) $line['quantity'] : '0',
                                is_numeric($line['unit_price'] ?? null) ? (string) $line['unit_price'] : '0',
                                is_numeric($line['discount_rate'] ?? null) ? (string) $line['discount_rate'] : '0',
                                $taxes->firstWhere('id', $line['tax_id'] ?? null),
                            );
                        @endphp
                        <tr role="row" wire:key="sale-line-{{ $index }}">
                            <td role="cell" data-label="Línea" class="px-2 py-1 text-xs text-slate-400">{{ $index + 1 }}</td>
                            <td role="cell" data-label="Código" class="px-2 py-1">
                                <input type="text" list="productos" wire:model.blur="lines.{{ $index }}.product_code"
                                       class="{{ $cell }} font-mono" placeholder="PRD0001">
                                @error("lines.{$index}.product_code")
                                    <p class="mt-0.5 text-[11px] text-red-600">{{ $message }}</p>
                                @enderror
                            </td>
                            <td role="cell" data-label="Descripción" class="px-2 py-1">
                                <input type="text" wire:model="lines.{{ $index }}.description" class="{{ $cell }}">
                            </td>
                            <td role="cell" data-label="Cantidad" class="px-2 py-1">
                                <input type="text" inputmode="decimal"
                                       wire:model.live.debounce.400ms="lines.{{ $index }}.quantity"
                                       class="{{ $cell }} text-right font-mono">
                            </td>
                            <td role="cell" data-label="Precio" class="px-2 py-1">
                                <input type="text" inputmode="decimal"
                                       wire:model.live.debounce.400ms="lines.{{ $index }}.unit_price"
                                       class="{{ $cell }} text-right font-mono">
                            </td>
                            <td role="cell" data-label="Descuento %" class="px-2 py-1">
                                <input type="text" inputmode="decimal"
                                       wire:model.live.debounce.400ms="lines.{{ $index }}.discount_rate"
                                       class="{{ $cell }} text-right font-mono">
                            </td>
                            <td role="cell" data-label="Impuesto" class="px-2 py-1">
                                <select wire:model.live="lines.{{ $index }}.tax_id" class="{{ $cell }}">
                                    <option value="">Sin impuesto</option>
                                    @foreach ($taxes as $taxOption)
                                        <option value="{{ $taxOption->id }}">{{ $taxOption->label() }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td role="cell" data-label="Total" class="px-2 py-1 text-right font-mono">{{ $lineTotal->total->format() }}</td>
                            <td role="cell" class="px-2 py-1 text-center">
                                @if (count($lines) > 1)
                                    <button type="button" wire:click="removeLine({{ $index }})"
                                            class="text-slate-400 hover:text-red-600">&times;</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="flex items-start justify-between border-t border-slate-200 px-3 py-2">
                <button type="button" wire:click="addLine"
                        class="text-sm text-slate-600 underline hover:text-slate-900">+ Agregar línea</button>

                <dl class="w-64 space-y-1 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Subtotal</dt>
                        <dd class="font-mono">{{ $totals['subtotal']->format() }}</dd>
                    </div>
                    @if ($totals['discount']->isPositive())
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Descuento</dt>
                            <dd class="font-mono">−{{ $totals['discount']->format() }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Impuesto</dt>
                        <dd class="font-mono">{{ $totals['tax']->format() }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-slate-300 pt-1 text-base font-bold">
                        <dt>Total</dt>
                        <dd class="font-mono">{{ $totals['total']->format() }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <a href="{{ route('sales.index') }}" wire:navigate
               class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">Cancelar</a>
            <button type="button" wire:click="saveDraft"
                    class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                Guardar borrador
            </button>
            <button type="submit" @disabled($totals['total']->isZero())
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300">
                Emitir factura
            </button>
        </div>
    </form>
</div>
