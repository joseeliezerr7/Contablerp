<x-report-shell
    title="Balance de comprobación"
    description="Saldo inicial, movimiento y saldo final de cada cuenta con actividad."
    :branches="$branches"
    :warning="$result['balanced'] ? null : 'Este balance no cuadra. Ejecuta «php artisan accounting:rebuild-balances --check» y revisa el libro diario.'"
>
    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-3 py-2 text-left font-semibold">Código</th>
                    <th role="columnheader" class="px-3 py-2 text-left font-semibold">Cuenta</th>
                    <th role="columnheader" class="px-3 py-2 text-right font-semibold">Saldo inicial</th>
                    <th role="columnheader" class="px-3 py-2 text-right font-semibold">Debe</th>
                    <th role="columnheader" class="px-3 py-2 text-right font-semibold">Haber</th>
                    <th role="columnheader" class="px-3 py-2 text-right font-semibold">Saldo deudor</th>
                    <th role="columnheader" class="px-3 py-2 text-right font-semibold">Saldo acreedor</th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($result['rows'] as $row)
                    <tr role="row" class="hover:bg-slate-50">
                        <td role="cell" data-label="Código" class="px-3 py-1 font-mono text-xs whitespace-nowrap">{{ $row->code }}</td>
                        <td role="cell" data-label="Cuenta" class="px-3 py-1">{{ $row->name }}</td>
                        <td role="cell" data-label="Saldo inicial" class="px-3 py-1 text-right font-mono">
                            {{ $row->opening->isZero() ? '—' : $row->opening->format() }}
                        </td>
                        <td role="cell" data-label="Debe" class="px-3 py-1 text-right font-mono">
                            {{ $row->debit->isZero() ? '—' : $row->debit->format() }}
                        </td>
                        <td role="cell" data-label="Haber" class="px-3 py-1 text-right font-mono">
                            {{ $row->credit->isZero() ? '—' : $row->credit->format() }}
                        </td>
                        <td role="cell" data-label="Saldo deudor" class="px-3 py-1 text-right font-mono">
                            {{ $row->debitBalance()->isZero() ? '—' : $row->debitBalance()->format() }}
                        </td>
                        <td role="cell" data-label="Saldo acreedor" class="px-3 py-1 text-right font-mono">
                            {{ $row->creditBalance()->isZero() ? '—' : $row->creditBalance()->format() }}
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="7" class="px-3 py-8 text-center text-slate-500">
                            No hay movimientos en el rango seleccionado.
                        </td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot role="rowgroup" class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                <tr role="row">
                    <td role="cell" colspan="3" class="px-3 py-2 text-right">Totales</td>
                    <td role="cell" data-label="Debe" class="px-3 py-2 text-right font-mono">{{ $result['debit']->format() }}</td>
                    <td role="cell" data-label="Haber" class="px-3 py-2 text-right font-mono">{{ $result['credit']->format() }}</td>
                    <td role="cell" data-label="Saldo deudor" class="px-3 py-2 text-right font-mono">{{ $result['closing_debit']->format() }}</td>
                    <td role="cell" data-label="Saldo acreedor" class="px-3 py-2 text-right font-mono">{{ $result['closing_credit']->format() }}</td>
                </tr>
                @if ($result['balanced'])
                    <tr role="row" class="text-emerald-700">
                        <td role="cell" colspan="7" class="px-3 py-1.5 text-right text-xs font-medium">
                            ✓ El balance cuadra
                        </td>
                    </tr>
                @endif
            </tfoot>
        </table>
    </div>
</x-report-shell>
