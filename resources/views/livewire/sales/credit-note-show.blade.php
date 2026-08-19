@php
    $card = 'rounded-xl border border-slate-200 bg-white shadow-sm';
    $dt = 'text-xs font-semibold tracking-wider text-slate-500 uppercase';
@endphp

<div class="space-y-5">
    <x-flash />

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('credit-notes.index') }}" wire:navigate class="text-xs text-slate-500 underline hover:text-slate-900">
                ← Volver a notas de crédito
            </a>
            <h2 class="mt-1 flex flex-wrap items-center gap-2 text-xl font-semibold tracking-tight">
                {{ $note->number ?? 'Borrador' }}
                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $note->status->badgeClasses() }}">
                    {{ $note->status->label() }}
                </span>
            </h2>
            <p class="text-sm text-slate-500">
                {{ $note->customer?->name ?? 'Consumidor final' }} · {{ $note->date->format('d/m/Y') }}
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            @can('print', $note)
                <a href="{{ route('credit-notes.print', $note->id) }}"
                   class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium hover:bg-slate-50">
                    Imprimir
                </a>
            @endcan
            @can('update', $note)
                <a href="{{ route('credit-notes.edit', $note->id) }}" wire:navigate
                   class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Editar
                </a>
            @endcan
        </div>
    </div>

    @if ($note->isVoided())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
            <p class="font-semibold">Nota de crédito anulada</p>
            <p class="mt-0.5">
                {{ $note->void_reason ? 'Motivo: '.$note->void_reason.' ' : '' }}
                El saldo que había rebajado volvió a la factura.
            </p>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- Contra qué factura va --}}
        <div class="{{ $card }} p-5 lg:col-span-2">
            <h3 class="mb-3 text-sm font-semibold">Qué acredita</h3>

            <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="{{ $dt }}">Factura</dt>
                    <dd class="mt-0.5">
                        @if ($note->sale)
                            <a href="{{ route('sales.show', $note->sale->id) }}" wire:navigate
                               class="font-mono text-xs underline hover:text-slate-900">{{ $note->sale->number }}</a>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="{{ $dt }}">Motivo</dt>
                    <dd class="mt-0.5">{{ $note->reason->label() }}</dd>
                </div>
                <div>
                    <dt class="{{ $dt }}">¿Volvió la mercadería?</dt>
                    {{-- Una nota por descuento o corrección de precio no mueve
                         existencias; una por devolución sí. --}}
                    <dd class="mt-0.5">
                        {{ $note->restocks ? 'Sí, entró a '.($note->warehouse?->name ?? 'bodega') : 'No, solo el importe' }}
                    </dd>
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="{{ $dt }}">Descripción</dt>
                    <dd class="mt-0.5">{{ $note->description }}</dd>
                </div>
            </dl>

            @if ($note->sale?->receivable)
                <div class="mt-4 border-t border-slate-100 pt-3">
                    <p class="{{ $dt }}">Efecto en la cuenta por cobrar</p>
                    <dl class="mt-1 grid gap-3 text-sm sm:grid-cols-3">
                        <div class="flex justify-between sm:block">
                            <dt class="text-slate-500">Original</dt>
                            <dd class="tabular-nums">{{ $note->sale->receivable->originalAmount()->format() }}</dd>
                        </div>
                        <div class="flex justify-between sm:block">
                            <dt class="text-slate-500">Acreditado</dt>
                            <dd class="tabular-nums">{{ $note->sale->receivable->creditedAmount()->format() }}</dd>
                        </div>
                        <div class="flex justify-between sm:block">
                            <dt class="text-slate-500">Saldo</dt>
                            <dd class="font-semibold tabular-nums">{{ $note->sale->receivable->balanceAmount()->format() }}</dd>
                        </div>
                    </dl>
                </div>
            @endif
        </div>

        {{-- Su propia autorización, distinta a la de la factura --}}
        @if ($note->cai)
            <div class="{{ $card }} p-5">
                <h3 class="mb-3 text-sm font-semibold">Datos fiscales</h3>
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="{{ $dt }}">CAI</dt>
                        <dd class="mt-0.5 font-mono text-xs break-all">{{ $note->cai }}</dd>
                    </div>
                    <div>
                        <dt class="{{ $dt }}">Fecha límite de emisión</dt>
                        <dd class="mt-0.5">{{ $note->fiscal_limit_date?->format('d/m/Y') ?? '—' }}</dd>
                    </div>
                </dl>
                <p class="mt-3 border-t border-slate-100 pt-2 text-xs text-slate-500">
                    Una nota de crédito lleva su propia autorización del SAR: es un
                    documento fiscal distinto de la factura, con su propia numeración.
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
                        <th role="columnheader" class="px-4 py-2 font-semibold">Impuesto</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Total</th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    @foreach ($note->items as $item)
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
                                {{ rtrim(rtrim($item->unit_price, '0'), '.') }}
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
                    <dd class="tabular-nums">{{ $note->subtotalAmount()->format() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-500">Impuestos</dt>
                    <dd class="tabular-nums">{{ $note->taxAmount()->format() }}</dd>
                </div>
                <div class="flex justify-between border-t border-slate-300 pt-1 text-base font-semibold">
                    <dt>Total acreditado</dt>
                    <dd class="tabular-nums">L {{ $note->totalAmount()->format() }}</dd>
                </div>
            </dl>
        </div>
    </div>

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
