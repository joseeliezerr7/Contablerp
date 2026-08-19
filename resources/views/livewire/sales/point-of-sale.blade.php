@php
    $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none';
    $cash = App\Domains\Sales\Enums\PaymentMethod::Cash->value;
@endphp

{{-- F2 vuelve al buscador y F4 cobra, desde cualquier sitio de la pantalla:
     en un mostrador la mano no vuelve al ratón. --}}
<div x-data
     @keydown.window.f2.prevent="$refs.term?.focus()"
     @keydown.window.f4.prevent="$wire.startCheckout()">

    <x-flash />

    {{-- Encabezado: caja abierta y lo que impide vender --}}
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Punto de venta</h2>
            <p class="text-sm text-slate-500">
                {{ $branch->name }}
                @if ($session)
                    · Caja <span class="font-mono">{{ $session->number }}</span>
                    ({{ $session->account->name }})
                @endif
            </p>
        </div>

        <p class="text-xs text-slate-500">
            <kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 font-mono">F2</kbd> buscar
            ·
            <kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 font-mono">F4</kbd> cobrar
        </p>
    </div>

    @if ($blocked)
        <div class="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ $blocked }}
        </div>
    @endif

    @if ($lastSale)
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
            <span>
                Última factura: <span class="font-mono font-semibold">{{ $lastSale->number }}</span>
                por <span class="font-mono">{{ $lastSale->totalAmount()->format() }}</span>
            </span>
            <a href="{{ route('sales.print', $lastSale->id) }}" target="_blank"
               class="font-medium underline">Imprimir</a>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-[1fr_320px]">
        {{-- Columna izquierda: buscador y líneas --}}
        <div class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <label for="term" class="mb-1 block text-sm font-medium text-slate-700">
                    Código de barras, SKU o nombre
                </label>

                {{-- El Enter manda el valor del campo, no la propiedad: la
                     pistola escribe y pulsa Enter más rápido de lo que tarda el
                     retardo del buscador, y sin esto se buscaría lo anterior. --}}
                <input id="term" type="text" x-ref="term" autofocus autocomplete="off"
                       wire:model.live.debounce.250ms="term"
                       wire:keydown.enter.prevent="submitTerm($event.target.value)"
                       class="{{ $input }} w-full text-lg" @disabled((bool) $blocked)>
                @error('term') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- Resultados: solo aparecen cuando hay más de uno que elegir --}}
                @if ($results->count() > 1)
                    <ul class="mt-2 divide-y divide-slate-100 rounded-md border border-slate-200">
                        @foreach ($results as $product)
                            <li>
                                <button type="button" wire:click="addProduct({{ $product->id }})"
                                        class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-slate-50">
                                    <span>
                                        <span class="font-mono text-xs text-slate-500">{{ $product->code }}</span>
                                        <span class="ml-2">{{ $product->name }}</span>
                                    </span>
                                    <span class="font-mono">
                                        {{ $product->priceIn(App\Domains\Catalog\Models\PriceList::default()?->id)?->format()
                                            ?? number_format((float) $product->price, 2) }}
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table class="table-stacked w-full text-sm">
                    <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                        <tr role="row">
                            <th role="columnheader" class="px-4 py-2 font-semibold">Producto</th>
                            <th role="columnheader" class="px-4 py-2 text-right font-semibold">Cantidad</th>
                            <th role="columnheader" class="px-4 py-2 text-right font-semibold">Precio</th>
                            <th role="columnheader" class="px-4 py-2 text-right font-semibold">Importe</th>
                            <th role="columnheader" class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup" class="divide-y divide-slate-100">
                        @forelse ($lines as $index => $line)
                            <tr role="row">
                                <td role="cell" data-label="Producto" class="px-4 py-1.5">
                                    <span class="font-mono text-xs text-slate-500">{{ $line['code'] }}</span>
                                    <span class="block">{{ $line['description'] }}</span>
                                </td>
                                <td role="cell" data-label="Cantidad" class="px-4 py-1.5 text-right">
                                    <input type="number" step="0.01" min="0.01"
                                           wire:model.live.debounce.400ms="lines.{{ $index }}.quantity"
                                           class="{{ $input }} w-24 text-right font-mono">
                                </td>
                                <td role="cell" data-label="Precio" class="px-4 py-1.5 text-right">
                                    <input type="number" step="0.01" min="0"
                                           wire:model.live.debounce.400ms="lines.{{ $index }}.unit_price"
                                           class="{{ $input }} w-28 text-right font-mono"
                                           @cannot('sales.invoices.override_price') readonly @endcannot>
                                </td>
                                <td role="cell" data-label="Importe" class="px-4 py-1.5 text-right font-mono">
                                    {{ number_format((float) $line['quantity'] * (float) $line['unit_price'], 2) }}
                                </td>
                                <td role="cell" class="px-4 py-1.5 text-right">
                                    <button type="button" wire:click="removeLine({{ $index }})"
                                            class="text-xs text-red-600 underline hover:text-red-800">Quitar</button>
                                </td>
                            </tr>
                        @empty
                            <tr role="row">
                                <td role="cell" colspan="5" class="px-4 py-10 text-center text-slate-500">
                                    Pasá un producto por la pistola o escribí su código.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Columna derecha: cliente y totales --}}
        <div class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <x-field label="Cliente" for="customerId" error="customerId">
                    <select id="customerId" wire:model="customerId" class="{{ $input }} w-full">
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <dl class="space-y-1 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Subtotal</dt>
                        <dd class="font-mono">{{ $totals['subtotal']->format() }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Impuesto</dt>
                        <dd class="font-mono">{{ $totals['tax']->format() }}</dd>
                    </div>
                    <div class="mt-2 flex justify-between border-t border-slate-200 pt-2">
                        <dt class="font-semibold">TOTAL</dt>
                        <dd class="font-mono text-2xl font-semibold">{{ $totals['total']->format() }}</dd>
                    </div>
                </dl>

                <button type="button" wire:click="startCheckout"
                        class="mt-4 w-full rounded-md bg-slate-900 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-40"
                        @disabled($blocked || $lines === [])>
                    Cobrar (F4)
                </button>

                @if ($lines !== [])
                    <button type="button" wire:click="clear" wire:confirm="¿Descartar la venta en curso?"
                            class="mt-2 w-full rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Descartar
                    </button>
                @endif
            </div>
        </div>
    </div>

    {{-- Diálogo de cobro --}}
    @if ($checkingOut)
        <x-modal title="Cobrar" onClose="cancelCheckout">
            <div class="space-y-4 p-5">
                @error('checkout')
                    <p class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>
                @enderror

                <div class="flex items-baseline justify-between rounded-md bg-slate-50 px-3 py-2">
                    <span class="text-sm text-slate-600">Total a cobrar</span>
                    <span class="font-mono text-2xl font-semibold">{{ $totals['total']->format() }}</span>
                </div>

                {{-- Medios de pago --}}
                <div class="space-y-2">
                    @foreach ($payments as $index => $payment)
                        <div class="grid gap-2 rounded-md border border-slate-200 p-3 sm:grid-cols-[1fr_1fr_auto]">
                            <div>
                                <label class="mb-1 block text-xs text-slate-500">Medio</label>
                                <select wire:model.live="payments.{{ $index }}.method" class="{{ $input }} w-full">
                                    @foreach ($methods as $method)
                                        <option value="{{ $method->value }}">{{ $method->label() }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="mb-1 block text-xs text-slate-500">Importe</label>
                                <input type="number" step="0.01" min="0"
                                       wire:model.live.debounce.400ms="payments.{{ $index }}.amount"
                                       class="{{ $input }} w-full text-right font-mono">
                            </div>

                            <div class="flex items-end">
                                @if (count($payments) > 1)
                                    <button type="button" wire:click="removePaymentLine({{ $index }})"
                                            class="pb-2 text-xs text-red-600 underline">Quitar</button>
                                @endif
                            </div>

                            @if ($payment['method'] !== $cash)
                                <div class="sm:col-span-3 grid gap-2 sm:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-xs text-slate-500">Cuenta donde entra</label>
                                        <select wire:model="payments.{{ $index }}.account_id" class="{{ $input }} w-full">
                                            <option value="">Elegí una cuenta…</option>
                                            @foreach ($bankAccounts as $account)
                                                <option value="{{ $account->id }}">
                                                    {{ $account->code }} — {{ $account->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs text-slate-500">
                                            Referencia (voucher o transferencia)
                                        </label>
                                        <input type="text" wire:model="payments.{{ $index }}.reference"
                                               class="{{ $input }} w-full">
                                    </div>
                                </div>
                            @else
                                <p class="text-xs text-slate-500 sm:col-span-3">
                                    El efectivo entra en la cuenta de tu caja abierta. Es lo que enlaza la venta
                                    con el arqueo del cierre.
                                </p>
                            @endif
                        </div>
                    @endforeach

                    <button type="button" wire:click="addPaymentLine"
                            class="text-xs text-slate-600 underline hover:text-slate-900">
                        + Añadir otro medio de pago
                    </button>
                </div>

                {{-- Efectivo entregado y vuelto --}}
                <div class="grid gap-3 border-t border-slate-200 pt-3 sm:grid-cols-2">
                    <div>
                        <label for="tendered" class="mb-1 block text-sm font-medium text-slate-700">
                            Efectivo recibido
                        </label>
                        <input id="tendered" type="number" step="0.01" min="0"
                               wire:model.live.debounce.300ms="tendered"
                               class="{{ $input }} w-full text-right font-mono text-lg">
                    </div>

                    <div class="flex flex-col justify-end">
                        <span class="text-sm text-slate-500">Vuelto</span>
                        <span class="font-mono text-3xl font-semibold {{ $change->isPositive() ? 'text-emerald-700' : '' }}">
                            {{ $change->format() }}
                        </span>
                    </div>
                </div>

                @if (! $pending->isZero())
                    <p class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        @if ($pending->isPositive())
                            Faltan <span class="font-mono">{{ $pending->format() }}</span> por cubrir.
                        @else
                            Los cobros exceden el total en
                            <span class="font-mono">{{ $pending->absolute()->format() }}</span>.
                            El vuelto se anota aparte, no se cobra de más.
                        @endif
                    </p>
                @endif
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                <button type="button" wire:click="cancelCheckout"
                        class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                    Cancelar
                </button>
                <button type="button" wire:click="checkout" wire:loading.attr="disabled"
                        class="rounded-md bg-emerald-600 px-6 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                        @disabled(! $pending->isZero())>
                    <span wire:loading.remove wire:target="checkout">Emitir factura</span>
                    <span wire:loading wire:target="checkout">Emitiendo…</span>
                </button>
            </div>
        </x-modal>
    @endif
</div>
