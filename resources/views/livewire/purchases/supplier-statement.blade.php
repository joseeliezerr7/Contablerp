@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4">
        <h2 class="text-lg font-semibold">Estado de cuenta del proveedor</h2>
        <p class="text-sm text-slate-500">Facturas, pagos y saldo acumulado.</p>
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <label class="text-sm">
            <span class="mb-1 block font-medium text-slate-700">Proveedor</span>
            <select wire:model.live="supplierId" class="{{ $input }} w-72">
                <option value="">Selecciona…</option>
                @foreach ($suppliers as $option)
                    <option value="{{ $option->id }}">{{ $option->code }} — {{ $option->name }}</option>
                @endforeach
            </select>
        </label>

        <label class="text-sm">
            <span class="mb-1 block font-medium text-slate-700">Desde</span>
            <input type="date" wire:model.live="from" class="{{ $input }}">
        </label>

        <label class="text-sm">
            <span class="mb-1 block font-medium text-slate-700">Hasta</span>
            <input type="date" wire:model.live="to" class="{{ $input }}">
        </label>
    </div>

    @if ($supplier === null)
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
            Selecciona un proveedor para ver su estado de cuenta.
        </div>
    @else
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="font-semibold">{{ $supplier->name }}</h3>
            <p class="text-sm text-slate-500">
                RTN {{ $supplier->tax_id ?: '—' }} · {{ $supplier->credit_days }} días de crédito
            </p>
        </div>

        <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="table-stacked w-full text-sm">
                <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-xs tracking-wider text-slate-500 uppercase">
                    <tr role="row">
                        <th role="columnheader" class="px-4 py-2 text-left font-semibold">Fecha</th>
                        <th role="columnheader" class="px-4 py-2 text-left font-semibold">Documento</th>
                        <th role="columnheader" class="px-4 py-2 text-left font-semibold">Concepto</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Cargo</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Pago</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Saldo</th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    <tr role="row" class="bg-slate-50/60">
                        <td role="cell" colspan="5" class="px-4 py-1.5 text-right text-xs font-semibold tracking-wider text-slate-500 uppercase">
                            Saldo anterior
                        </td>
                        <td role="cell" data-label="Saldo" class="px-4 py-1.5 text-right font-mono font-semibold">{{ $statement['opening']->format() }}</td>
                    </tr>

                    @forelse ($statement['rows'] as $row)
                        <tr role="row" class="hover:bg-slate-50">
                            <td role="cell" data-label="Fecha" class="px-4 py-1.5 whitespace-nowrap">{{ $row['date']->format('d/m/Y') }}</td>
                            <td role="cell" data-label="Documento" class="px-4 py-1.5 font-mono text-xs">{{ $row['document'] }}</td>
                            <td role="cell" data-label="Concepto" class="px-4 py-1.5">{{ $row['concept'] }}</td>
                            <td role="cell" data-label="Cargo" class="px-4 py-1.5 text-right font-mono">
                                {{ $row['charge']->isZero() ? '—' : $row['charge']->format() }}
                            </td>
                            <td role="cell" data-label="Abono" class="px-4 py-1.5 text-right font-mono">
                                {{ $row['payment']->isZero() ? '—' : $row['payment']->format() }}
                            </td>
                            <td role="cell" data-label="Saldo" class="px-4 py-1.5 text-right font-mono">{{ $row['balance']->format() }}</td>
                        </tr>
                    @empty
                        <tr role="row">
                            <td role="cell" colspan="6" class="px-4 py-8 text-center text-slate-500">
                                Sin movimientos en el rango seleccionado.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot role="rowgroup" class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                    <tr role="row">
                        <td role="cell" colspan="5" class="px-4 py-2 text-right">
                            Saldo al {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}
                        </td>
                        <td role="cell" data-label="Saldo" class="px-4 py-2 text-right font-mono">{{ $statement['closing']->format() }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
