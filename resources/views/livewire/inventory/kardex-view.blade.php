@php
    $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none';
    $trim = fn (string $n) => rtrim(rtrim(ltrim($n, '-'), '0'), '.') ?: '0';
@endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Kardex</h2>
            <p class="text-sm text-slate-500">
                Movimientos de un producto, en el orden en que se registraron.
            </p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <select wire:model.live="productId" class="{{ $input }} w-64">
                <option value="">Elige un producto…</option>
                @foreach ($products as $option)
                    <option value="{{ $option->id }}">{{ $option->code }} — {{ $option->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="warehouseId" class="{{ $input }}">
                <option value="">Todas las bodegas</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->code }}</option>
                @endforeach
            </select>
            <input type="date" wire:model.live="from" class="{{ $input }}">
            <input type="date" wire:model.live="to" class="{{ $input }}">
        </div>
    </div>

    @if (! $product)
        <div class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-12 text-center text-slate-500">
            Elige un producto para ver su kardex.
        </div>
    @else
        <div class="mb-3 flex flex-wrap gap-6 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm">
            <div>
                <span class="text-slate-500">Entradas</span>
                <span class="ml-2 font-mono font-semibold">{{ $inTotal->format() }}</span>
            </div>
            <div>
                <span class="text-slate-500">Salidas</span>
                <span class="ml-2 font-mono font-semibold">{{ $outTotal->absolute()->format() }}</span>
            </div>
            <div>
                <span class="text-slate-500">Saldo</span>
                <span class="ml-2 font-mono font-semibold">
                    {{ $movements->last()?->balanceValue()->format() ?? '0.00' }}
                </span>
            </div>
        </div>

        <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="table-stacked w-full text-sm">
                <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                    <tr role="row">
                        <th role="columnheader" class="px-3 py-2 font-semibold">Fecha</th>
                        <th role="columnheader" class="px-3 py-2 font-semibold">Concepto</th>
                        <th role="columnheader" class="px-3 py-2 font-semibold">Documento</th>
                        <th role="columnheader" class="px-3 py-2 font-semibold">Bodega</th>
                        <th role="columnheader" class="px-3 py-2 text-right font-semibold">Entrada</th>
                        <th role="columnheader" class="px-3 py-2 text-right font-semibold">Salida</th>
                        <th role="columnheader" class="px-3 py-2 text-right font-semibold">Costo unit.</th>
                        <th role="columnheader" class="px-3 py-2 text-right font-semibold">Saldo</th>
                        <th role="columnheader" class="px-3 py-2 text-right font-semibold">Valor saldo</th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    @forelse ($movements as $movement)
                        <tr role="row" class="hover:bg-slate-50">
                            <td role="cell" data-label="Fecha" class="px-3 py-1.5 whitespace-nowrap">{{ $movement->date->format('d/m/Y') }}</td>
                            <td role="cell" data-label="Concepto" class="px-3 py-1.5">
                                {{ $movement->type->label() }}
                                @if ($movement->description)
                                    <span class="text-xs text-slate-400">· {{ $movement->description }}</span>
                                @endif
                            </td>
                            <td role="cell" data-label="Documento" class="px-3 py-1.5 font-mono text-xs text-slate-500">{{ $movement->reference ?? '—' }}</td>
                            <td role="cell" data-label="Bodega" class="px-3 py-1.5 text-slate-600">{{ $movement->warehouse->code }}</td>
                            <td role="cell" data-label="Entrada" class="px-3 py-1.5 text-right font-mono text-emerald-700">
                                {{ $movement->isInbound() ? $trim($movement->quantity) : '—' }}
                            </td>
                            <td role="cell" data-label="Salida" class="px-3 py-1.5 text-right font-mono text-red-600">
                                {{ $movement->isInbound() ? '—' : $trim($movement->quantity) }}
                            </td>
                            <td role="cell" data-label="Costo unit." class="px-3 py-1.5 text-right font-mono text-slate-500">
                                {{ $movement->unitCost()->format() }}
                            </td>
                            <td role="cell" data-label="Saldo" class="px-3 py-1.5 text-right font-mono">{{ $trim($movement->balance_quantity) }}</td>
                            <td role="cell" data-label="Valor saldo" class="px-3 py-1.5 text-right font-mono">{{ $movement->balanceValue()->format() }}</td>
                        </tr>
                    @empty
                        <tr role="row">
                            <td role="cell" colspan="9" class="px-4 py-8 text-center text-slate-500">
                                Este producto no tiene movimientos en el filtro.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
