@php
    $card = 'rounded-xl border border-slate-200 bg-white shadow-sm';
    $dt = 'text-xs font-semibold tracking-wider text-slate-500 uppercase';

    $sube = $adjustment->items->filter->isIncrease();
    $baja = $adjustment->items->reject->isIncrease();
@endphp

<div class="space-y-5">
    <x-flash />

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('inventory.adjustments.index') }}" wire:navigate class="text-xs text-slate-500 underline hover:text-slate-900">
                ← Volver a ajustes
            </a>
            <h2 class="mt-1 flex flex-wrap items-center gap-2 text-xl font-semibold tracking-tight">
                {{ $adjustment->number ?? 'Borrador' }}
                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $adjustment->status->badgeClasses() }}">
                    {{ $adjustment->status->label() }}
                </span>
            </h2>
            <p class="text-sm text-slate-500">
                {{ $adjustment->reason->label() }} · {{ $adjustment->date->format('d/m/Y') }} ·
                {{ $adjustment->warehouse?->name }}
            </p>
        </div>

        <p class="text-right">
            <span class="{{ $dt }}">Efecto en el inventario</span>
            <span class="block text-2xl font-semibold tabular-nums {{ $adjustment->valueAmount()->isNegative() ? 'text-red-700' : '' }}">
                L {{ $adjustment->valueAmount()->format() }}
            </span>
            <span class="block text-xs text-slate-500">
                {{ $adjustment->valueAmount()->isNegative() ? 'el inventario bajó' : 'el inventario subió' }}
            </span>
        </p>
    </div>

    @if ($adjustment->isVoided())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
            <p class="font-semibold">Ajuste anulado</p>
            <p class="mt-0.5">
                {{ $adjustment->void_reason ? 'Motivo: '.$adjustment->void_reason.' ' : '' }}
                Su partida se revirtió y las existencias volvieron a como estaban.
            </p>
        </div>
    @endif

    {{-- Datos --}}
    <div class="{{ $card }} p-5">
        <h3 class="mb-3 text-sm font-semibold">Datos del documento</h3>

        <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="{{ $dt }}">Motivo</dt>
                <dd class="mt-0.5">{{ $adjustment->reason->label() }}</dd>
            </div>
            <div>
                <dt class="{{ $dt }}">Bodega</dt>
                <dd class="mt-0.5">{{ $adjustment->warehouse?->name ?? '—' }}</dd>
                <dd class="text-xs text-slate-500">{{ $adjustment->branch?->name }}</dd>
            </div>
            <div>
                <dt class="{{ $dt }}">Contra qué cuenta</dt>
                <dd class="mt-0.5">{{ $adjustment->adjustmentAccount?->name ?? 'La del mapeo por módulo' }}</dd>
                <dd class="font-mono text-[10px] text-slate-400">{{ $adjustment->adjustmentAccount?->code }}</dd>
            </div>
            <div>
                <dt class="{{ $dt }}">Renglones</dt>
                <dd class="mt-0.5">
                    {{ $sube->count() }} {{ $sube->count() === 1 ? 'entrada' : 'entradas' }},
                    {{ $baja->count() }} {{ $baja->count() === 1 ? 'salida' : 'salidas' }}
                </dd>
            </div>
        </dl>

        @if ($adjustment->notes)
            <div class="mt-4 border-t border-slate-100 pt-3">
                <p class="{{ $dt }}">Notas</p>
                <p class="mt-0.5 text-sm">{{ $adjustment->notes }}</p>
            </div>
        @endif
    </div>

    {{-- Renglones --}}
    <div class="{{ $card }} overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-3">
            <h3 class="text-sm font-semibold">Qué se ajustó</h3>
            <p class="text-xs text-slate-500">Cantidad con signo: positiva si sobró, negativa si faltó.</p>
        </div>

        <div class="table-stacked-wrap overflow-x-auto">
            <table class="table-stacked w-full text-sm">
                <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                    <tr role="row">
                        <th role="columnheader" class="px-4 py-2 font-semibold">Producto</th>
                        <th role="columnheader" class="px-4 py-2 font-semibold">Detalle</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Cantidad</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Costo unitario</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Valor</th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    @foreach ($adjustment->items as $item)
                        <tr role="row">
                            <td role="cell" data-label="Producto" class="px-4 py-1.5">
                                {{ $item->product?->name ?? '—' }}
                                @if ($item->product?->code)
                                    <span class="block font-mono text-[10px] text-slate-400">{{ $item->product->code }}</span>
                                @endif
                            </td>
                            <td role="cell" data-label="Detalle" class="px-4 py-1.5 text-xs text-slate-600">
                                {{ $item->description ?: '—' }}
                            </td>
                            <td role="cell" data-label="Cantidad" class="px-4 py-1.5 text-right tabular-nums {{ $item->isIncrease() ? 'text-emerald-700' : 'text-red-700' }}">
                                {{ $item->isIncrease() ? '+' : '' }}{{ rtrim(rtrim($item->quantity, '0'), '.') }}
                            </td>
                            <td role="cell" data-label="Costo unitario" class="px-4 py-1.5 text-right tabular-nums">
                                {{ rtrim(rtrim($item->unit_cost, '0'), '.') }}
                            </td>
                            <td role="cell" data-label="Valor" class="px-4 py-1.5 text-right font-medium tabular-nums">
                                {{ $item->valueAmount()->format() }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-3">
            <dl class="flex w-full max-w-xs justify-between text-base font-semibold">
                <dt>Valor neto</dt>
                <dd class="tabular-nums">L {{ $adjustment->valueAmount()->format() }}</dd>
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
