@php
    $card = 'rounded-xl border border-slate-200 bg-white shadow-sm';
    $dt = 'text-xs font-semibold tracking-wider text-slate-500 uppercase';
@endphp

<div class="space-y-5">
    <x-flash />

    {{-- Encabezado --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('sales.index') }}" wire:navigate class="text-xs text-slate-500 underline hover:text-slate-900">
                ← Volver a facturas
            </a>
            <h2 class="mt-1 flex flex-wrap items-center gap-2 text-xl font-semibold tracking-tight">
                {{ $sale->number ?? 'Borrador' }}
                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $sale->status->badgeClasses() }}">
                    {{ $sale->status->label() }}
                </span>
            </h2>
            <p class="text-sm text-slate-500">
                {{ $sale->customer?->name ?? 'Consumidor final' }} ·
                {{ $sale->date->format('d/m/Y') }}
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            @can('print', $sale)
                <a href="{{ route('sales.print', $sale->id) }}"
                   class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium hover:bg-slate-50">
                    Imprimir
                </a>
            @endcan
            @can('update', $sale)
                <a href="{{ route('sales.edit', $sale->id) }}" wire:navigate
                   class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Editar
                </a>
            @endcan
        </div>
    </div>

    @if ($sale->isVoided())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
            <p class="font-semibold">Factura anulada</p>
            <p class="mt-0.5">
                Anulada el {{ $sale->voided_at?->format('d/m/Y H:i') }}.
                {{ $sale->void_reason ? 'Motivo: '.$sale->void_reason : '' }}
                Se conserva porque su número fiscal no se puede reutilizar.
            </p>
        </div>
    @endif

    {{-- Datos de la factura --}}
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="{{ $card }} p-5 lg:col-span-2">
            <h3 class="mb-3 text-sm font-semibold">Datos del documento</h3>

            <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="{{ $dt }}">Cliente</dt>
                    <dd class="mt-0.5">{{ $sale->customer?->name ?? 'Consumidor final' }}</dd>
                    @if ($sale->customer?->tax_id)
                        <dd class="text-xs text-slate-500">RTN {{ $sale->customer->tax_id }}</dd>
                    @endif
                </div>
                <div>
                    <dt class="{{ $dt }}">Condición</dt>
                    <dd class="mt-0.5">{{ $sale->payment_condition->label() }}</dd>
                    @if ($sale->isOnCredit() && $sale->due_date)
                        <dd class="text-xs text-slate-500">Vence el {{ $sale->due_date->format('d/m/Y') }}</dd>
                    @endif
                </div>
                <div>
                    <dt class="{{ $dt }}">Sucursal</dt>
                    <dd class="mt-0.5">{{ $sale->branch?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="{{ $dt }}">Bodega</dt>
                    <dd class="mt-0.5">{{ $sale->warehouse?->name ?? '—' }}</dd>
                </div>
                @if ($sale->reference)
                    <div>
                        <dt class="{{ $dt }}">Referencia</dt>
                        <dd class="mt-0.5">{{ $sale->reference }}</dd>
                    </div>
                @endif
                @if ($sale->issued_at)
                    <div>
                        <dt class="{{ $dt }}">Emitida</dt>
                        <dd class="mt-0.5">{{ $sale->issued_at->format('d/m/Y H:i') }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- Lo que exige el SAR, congelado en la factura al emitirla --}}
        @if ($sale->cai)
            <div class="{{ $card }} p-5">
                <h3 class="mb-3 text-sm font-semibold">Datos fiscales</h3>
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="{{ $dt }}">CAI</dt>
                        <dd class="mt-0.5 font-mono text-xs break-all">{{ $sale->cai }}</dd>
                    </div>
                    <div>
                        <dt class="{{ $dt }}">Rango autorizado</dt>
                        <dd class="mt-0.5 font-mono text-xs">{{ $sale->fiscalRangeLabel() ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="{{ $dt }}">Fecha límite de emisión</dt>
                        <dd class="mt-0.5">{{ $sale->fiscal_limit_date?->format('d/m/Y') ?? '—' }}</dd>
                    </div>
                </dl>
                <p class="mt-3 border-t border-slate-100 pt-2 text-xs text-slate-500">
                    Son los datos congelados al emitir. No cambian aunque después se
                    registre otra autorización.
                </p>
            </div>
        @endif
    </div>

    {{-- Renglones --}}
    <div class="{{ $card }} overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-3">
            <h3 class="text-sm font-semibold">Detalle</h3>
        </div>

        <div class="table-stacked-wrap overflow-x-auto">
            <table class="table-stacked w-full text-sm">
                <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                    <tr role="row">
                        <th role="columnheader" class="px-4 py-2 font-semibold">Producto</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Cantidad</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Precio</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Descuento</th>
                        <th role="columnheader" class="px-4 py-2 font-semibold">Impuesto</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Total</th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    @foreach ($sale->items as $item)
                        <tr role="row">
                            <td role="cell" data-label="Producto" class="px-4 py-1.5">
                                {{ $item->description ?: $item->product?->name }}
                                @if ($item->product?->code)
                                    <span class="block font-mono text-[10px] text-slate-400">{{ $item->product->code }}</span>
                                @endif
                            </td>
                            <td role="cell" data-label="Cantidad" class="px-4 py-1.5 text-right tabular-nums">
                                {{ rtrim(rtrim($item->quantity, '0'), '.') }}
                            </td>
                            <td role="cell" data-label="Precio" class="px-4 py-1.5 text-right tabular-nums">
                                {{ $item->unitPriceAmount()->format() }}
                            </td>
                            <td role="cell" data-label="Descuento" class="px-4 py-1.5 text-right tabular-nums">
                                {{ $item->discountAmount()->isZero() ? '—' : $item->discountAmount()->format() }}
                            </td>
                            <td role="cell" data-label="Impuesto" class="px-4 py-1.5 text-xs text-slate-600">
                                {{ $item->tax?->name ?? 'Exento' }}
                            </td>
                            <td role="cell" data-label="Total" class="px-4 py-1.5 text-right font-medium tabular-nums">
                                {{ $item->totalAmount()->format() }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-3">
            <dl class="w-full max-w-xs space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt class="text-slate-500">Subtotal</dt>
                    <dd class="tabular-nums">{{ $sale->subtotalAmount()->format() }}</dd>
                </div>
                @if (! $sale->discountAmount()->isZero())
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Descuento</dt>
                        <dd class="tabular-nums">−{{ $sale->discountAmount()->format() }}</dd>
                    </div>
                @endif
                <div class="flex justify-between">
                    <dt class="text-slate-500">Impuestos</dt>
                    <dd class="tabular-nums">{{ $sale->taxAmount()->format() }}</dd>
                </div>
                <div class="flex justify-between border-t border-slate-300 pt-1 text-base font-semibold">
                    <dt>Total</dt>
                    <dd class="tabular-nums">L {{ $sale->totalAmount()->format() }}</dd>
                </div>
            </dl>
        </div>
    </div>

    {{-- Cómo se cobró y qué quedó pendiente --}}
    <div class="grid gap-4 lg:grid-cols-2">
        @if ($sale->payments->isNotEmpty())
            <div class="{{ $card }} p-5">
                <h3 class="mb-3 text-sm font-semibold">Cómo se pagó</h3>
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach ($sale->payments as $payment)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span class="min-w-0">
                                <span class="block">{{ $payment->method->label() }}</span>
                                <span class="block truncate text-xs text-slate-500">
                                    {{ $payment->account?->name }}{{ $payment->reference ? ' · '.$payment->reference : '' }}
                                </span>
                            </span>
                            <span class="shrink-0 font-medium tabular-nums">{{ $payment->amountMoney()->format() }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($sale->receivable)
            <div class="{{ $card }} p-5">
                <h3 class="mb-3 text-sm font-semibold">Cuenta por cobrar</h3>

                <dl class="space-y-1 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Original</dt>
                        <dd class="tabular-nums">{{ $sale->receivable->originalAmount()->format() }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Saldo</dt>
                        <dd class="font-semibold tabular-nums">{{ $sale->receivable->balanceAmount()->format() }}</dd>
                    </div>
                </dl>

                @if ($sale->receivable->applications->isNotEmpty())
                    <p class="mt-4 {{ $dt }}">Cobros aplicados</p>
                    <ul class="mt-1 divide-y divide-slate-100 text-sm">
                        @foreach ($sale->receivable->applications as $application)
                            <li class="flex items-center justify-between gap-3 py-1.5">
                                <a href="{{ route('receipts.show', $application->receipt?->id) }}" wire:navigate
                                   class="min-w-0 truncate underline hover:text-slate-900">
                                    {{ $application->receipt?->number ?? 'Recibo' }}
                                    <span class="text-xs text-slate-500">
                                        {{ $application->receipt?->date?->format('d/m/Y') }}
                                    </span>
                                </a>
                                <span class="shrink-0 tabular-nums">{{ $application->amountMoney()->format() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </div>

    {{-- El asiento que generó --}}
    @if ($entry)
        <div class="{{ $card }} flex flex-wrap items-center justify-between gap-3 p-5">
            <div>
                <h3 class="text-sm font-semibold">Partida contable</h3>
                <p class="text-sm text-slate-500">
                    {{ $entry->number }} · {{ $entry->date->format('d/m/Y') }} · {{ $entry->concept }}
                </p>
            </div>
            @can('view', $entry)
                <a href="{{ route('journal.edit', $entry->id) }}" wire:navigate
                   class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium hover:bg-slate-50">
                    Ver la partida
                </a>
            @endcan
        </div>
    @endif
</div>
