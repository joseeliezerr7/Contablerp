@php
    $card = 'rounded-xl border border-slate-200 bg-white shadow-sm';
    $dt = 'text-xs font-semibold tracking-wider text-slate-500 uppercase';
@endphp

<div class="space-y-5">
    <x-flash />

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('inventory.transfers.index') }}" wire:navigate class="text-xs text-slate-500 underline hover:text-slate-900">
                ← Volver a traslados
            </a>
            <h2 class="mt-1 flex flex-wrap items-center gap-2 text-xl font-semibold tracking-tight">
                {{ $transfer->number ?? 'Borrador' }}
                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $transfer->status->badgeClasses() }}">
                    {{ $transfer->status->label() }}
                </span>
            </h2>
            <p class="text-sm text-slate-500">{{ $transfer->date->format('d/m/Y') }}</p>
        </div>

        <p class="text-right">
            <span class="{{ $dt }}">Valor trasladado</span>
            <span class="block text-2xl font-semibold tabular-nums">L {{ $transfer->valueAmount()->format() }}</span>
        </p>
    </div>

    @if ($transfer->isVoided())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
            <p class="font-semibold">Traslado anulado</p>
            <p class="mt-0.5">
                {{ $transfer->void_reason ? 'Motivo: '.$transfer->void_reason.' ' : '' }}
                La mercadería volvió a la bodega de origen.
            </p>
        </div>
    @endif

    {{-- De dónde a dónde --}}
    <div class="{{ $card }} p-5">
        <div class="flex flex-wrap items-center gap-4 text-sm">
            <div class="min-w-0">
                <p class="{{ $dt }}">Sale de</p>
                <p class="mt-0.5 font-medium">{{ $transfer->fromWarehouse?->name ?? '—' }}</p>
                <p class="font-mono text-[10px] text-slate-400">{{ $transfer->fromWarehouse?->code }}</p>
            </div>

            <svg class="h-5 w-5 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M10.3 4.3a1 1 0 011.4 0l5 5a1 1 0 010 1.4l-5 5a1 1 0 01-1.4-1.4L13.6 11H4a1 1 0 110-2h9.6l-3.3-3.3a1 1 0 010-1.4z" clip-rule="evenodd"/>
            </svg>

            <div class="min-w-0">
                <p class="{{ $dt }}">Entra a</p>
                <p class="mt-0.5 font-medium">{{ $transfer->toWarehouse?->name ?? '—' }}</p>
                <p class="font-mono text-[10px] text-slate-400">{{ $transfer->toWarehouse?->code }}</p>
            </div>

            <div class="ml-auto min-w-0">
                <p class="{{ $dt }}">Sucursal</p>
                <p class="mt-0.5">{{ $transfer->branch?->name ?? '—' }}</p>
            </div>
        </div>

        @if ($transfer->notes)
            <div class="mt-4 border-t border-slate-100 pt-3">
                <p class="{{ $dt }}">Notas</p>
                <p class="mt-0.5 text-sm">{{ $transfer->notes }}</p>
            </div>
        @endif
    </div>

    {{-- Renglones --}}
    <div class="{{ $card }} overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-3">
            <h3 class="text-sm font-semibold">Qué se movió</h3>
        </div>

        <div class="table-stacked-wrap overflow-x-auto">
            <table class="table-stacked w-full text-sm">
                <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                    <tr role="row">
                        <th role="columnheader" class="px-4 py-2 font-semibold">Producto</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Cantidad</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Costo unitario</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Valor</th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    @foreach ($transfer->items as $item)
                        <tr role="row">
                            <td role="cell" data-label="Producto" class="px-4 py-1.5">
                                {{ $item->product?->name ?? '—' }}
                                @if ($item->product?->code)
                                    <span class="block font-mono text-[10px] text-slate-400">{{ $item->product->code }}</span>
                                @endif
                            </td>
                            <td role="cell" data-label="Cantidad" class="px-4 py-1.5 text-right tabular-nums">
                                {{ rtrim(rtrim($item->quantity, '0'), '.') }}
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
                <dt>Total</dt>
                <dd class="tabular-nums">L {{ $transfer->valueAmount()->format() }}</dd>
            </dl>
        </div>
    </div>

    {{-- Un traslado no cambia el patrimonio: la misma mercadería, al mismo
         costo, en otra bodega. Decirlo evita la consulta de siempre. --}}
    <p class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
        Un traslado no genera partida contable: la mercadería no cambia de valor ni sale
        de la empresa, solo de bodega. Lo que se mueve es el kardex, no el libro.
    </p>
</div>
