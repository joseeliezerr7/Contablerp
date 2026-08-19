@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Antigüedad de saldos por pagar</h2>
            <p class="text-sm text-slate-500">Lo que la empresa debe, por proveedor y días de atraso.</p>
        </div>

        <div class="flex items-end gap-2">
            <label class="text-sm">
                <span class="mb-1 block font-medium text-slate-700">Al</span>
                <input type="date" wire:model.live="asOf" class="{{ $input }}">
            </label>
            <button type="button" wire:click="exportPdf"
                    class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50">PDF</button>
            <button type="button" wire:click="exportExcel"
                    class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50">Excel</button>
        </div>
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-3 py-2 text-left font-semibold">Proveedor</th>
                    <th role="columnheader" class="px-3 py-2 text-right font-semibold">Corriente</th>
                    <th role="columnheader" class="px-3 py-2 text-right font-semibold">1–30</th>
                    <th role="columnheader" class="px-3 py-2 text-right font-semibold">31–60</th>
                    <th role="columnheader" class="px-3 py-2 text-right font-semibold">61–90</th>
                    <th role="columnheader" class="px-3 py-2 text-right font-semibold">Más de 90</th>
                    <th role="columnheader" class="px-3 py-2 text-right font-semibold">Total</th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($result['rows'] as $row)
                    <tr role="row" class="hover:bg-slate-50">
                        <td role="cell" data-label="Proveedor" class="px-3 py-1.5">
                            <span class="font-mono text-xs text-slate-500">{{ $row['supplier']->code }}</span>
                            {{ $row['supplier']->name }}
                        </td>
                        <td role="cell" data-label="Corriente" class="px-3 py-1.5 text-right font-mono">{{ $row['current']->isZero() ? '—' : $row['current']->format() }}</td>
                        <td role="cell" data-label="1–30" class="px-3 py-1.5 text-right font-mono">{{ $row['d30']->isZero() ? '—' : $row['d30']->format() }}</td>
                        <td role="cell" data-label="31–60" class="px-3 py-1.5 text-right font-mono">{{ $row['d60']->isZero() ? '—' : $row['d60']->format() }}</td>
                        <td role="cell" data-label="61–90" class="px-3 py-1.5 text-right font-mono text-amber-700">{{ $row['d90']->isZero() ? '—' : $row['d90']->format() }}</td>
                        <td role="cell" data-label="Más de 90" class="px-3 py-1.5 text-right font-mono text-red-700">{{ $row['over']->isZero() ? '—' : $row['over']->format() }}</td>
                        <td role="cell" data-label="Total" class="px-3 py-1.5 text-right font-mono font-semibold">{{ $row['total']->format() }}</td>
                    </tr>
                @empty
                    <tr role="row"><td role="cell" colspan="7" class="px-3 py-8 text-center text-slate-500">No hay saldos pendientes.</td></tr>
                @endforelse
            </tbody>
            <tfoot role="rowgroup" class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                <tr role="row">
                    <td role="cell" colspan="1" class="px-3 py-2">Totales</td>
                    <td role="cell" data-label="Corriente" class="px-3 py-2 text-right font-mono">{{ $result['totals']['current']->format() }}</td>
                    <td role="cell" data-label="1–30" class="px-3 py-2 text-right font-mono">{{ $result['totals']['d30']->format() }}</td>
                    <td role="cell" data-label="31–60" class="px-3 py-2 text-right font-mono">{{ $result['totals']['d60']->format() }}</td>
                    <td role="cell" data-label="61–90" class="px-3 py-2 text-right font-mono">{{ $result['totals']['d90']->format() }}</td>
                    <td role="cell" data-label="Más de 90" class="px-3 py-2 text-right font-mono">{{ $result['totals']['over']->format() }}</td>
                    <td role="cell" data-label="Total" class="px-3 py-2 text-right font-mono">{{ $result['totals']['total']->format() }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
