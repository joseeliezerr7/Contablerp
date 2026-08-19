<x-report-shell
    title="Estado de resultados"
    description="Ingresos, costos y gastos del período. Excluye las partidas de cierre del ejercicio."
    :branches="$branches"
>
    <div class="mx-auto max-w-3xl overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">

                <tr class="bg-slate-50">
                    <td class="px-4 py-2 font-semibold tracking-wide uppercase">Ingresos</td>
                    <td class="px-4 py-2"></td>
                </tr>
                @forelse ($result['income'] as $row)
                    <tr>
                        <td class="py-1 pr-4 pl-8">{{ $row->label() }}</td>
                        <td class="px-4 py-1 text-right font-mono">{{ $row->statementBalance()->format() }}</td>
                    </tr>
                @empty
                    <tr><td class="py-1 pr-4 pl-8 text-slate-500" colspan="2">Sin movimientos</td></tr>
                @endforelse
                <tr class="border-t border-slate-300 font-semibold">
                    <td class="px-4 py-1.5">Total de ingresos</td>
                    <td class="px-4 py-1.5 text-right font-mono">{{ $result['total_income']->format() }}</td>
                </tr>

                <tr class="bg-slate-50">
                    <td class="px-4 py-2 font-semibold tracking-wide uppercase">Costos</td>
                    <td class="px-4 py-2"></td>
                </tr>
                @forelse ($result['cost'] as $row)
                    <tr>
                        <td class="py-1 pr-4 pl-8">{{ $row->label() }}</td>
                        <td class="px-4 py-1 text-right font-mono">{{ $row->statementBalance()->format() }}</td>
                    </tr>
                @empty
                    <tr><td class="py-1 pr-4 pl-8 text-slate-500" colspan="2">Sin movimientos</td></tr>
                @endforelse
                <tr class="border-t border-slate-300 font-semibold">
                    <td class="px-4 py-1.5">Total de costos</td>
                    <td class="px-4 py-1.5 text-right font-mono">{{ $result['total_cost']->format() }}</td>
                </tr>

                <tr class="bg-slate-100 font-semibold">
                    <td class="px-4 py-2">UTILIDAD BRUTA</td>
                    <td class="px-4 py-2 text-right font-mono">{{ $result['gross_profit']->format() }}</td>
                </tr>

                <tr class="bg-slate-50">
                    <td class="px-4 py-2 font-semibold tracking-wide uppercase">Gastos</td>
                    <td class="px-4 py-2"></td>
                </tr>
                @forelse ($result['expense'] as $row)
                    <tr>
                        <td class="py-1 pr-4 pl-8">{{ $row->label() }}</td>
                        <td class="px-4 py-1 text-right font-mono">{{ $row->statementBalance()->format() }}</td>
                    </tr>
                @empty
                    <tr><td class="py-1 pr-4 pl-8 text-slate-500" colspan="2">Sin movimientos</td></tr>
                @endforelse
                <tr class="border-t border-slate-300 font-semibold">
                    <td class="px-4 py-1.5">Total de gastos</td>
                    <td class="px-4 py-1.5 text-right font-mono">{{ $result['total_expense']->format() }}</td>
                </tr>

                <tr class="border-t-2 border-slate-800 text-base font-bold
                           {{ $result['net_profit']->isNegative() ? 'text-red-700' : 'text-emerald-700' }}">
                    <td class="px-4 py-3">
                        {{ $result['net_profit']->isNegative() ? 'PÉRDIDA NETA DEL PERÍODO' : 'UTILIDAD NETA DEL PERÍODO' }}
                    </td>
                    <td class="px-4 py-3 text-right font-mono">{{ $result['net_profit']->format() }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</x-report-shell>
