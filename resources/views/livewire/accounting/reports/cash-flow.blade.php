<x-report-shell
    title="Estado de flujo de efectivo"
    description="Método directo. Los importes positivos son entradas y los negativos, salidas."
    :branches="$branches"
    :warning="$result['reconciled'] ? null : 'El flujo calculado no coincide con la variación real del efectivo. Revisa la clasificación de las cuentas.'"
>
    <div class="mx-auto max-w-3xl overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">
                @foreach ($result['sections'] as $section)
                    <tr class="bg-slate-50">
                        <td class="px-4 py-2 font-semibold tracking-wide uppercase">
                            Actividades de {{ mb_strtolower($section['label']) }}
                        </td>
                        <td class="px-4 py-2"></td>
                    </tr>

                    @forelse ($section['rows'] as $row)
                        <tr>
                            <td class="py-1 pr-4 pl-8 text-slate-700">{{ $row['code'] }} — {{ $row['name'] }}</td>
                            <td class="px-4 py-1 text-right font-mono {{ $row['amount']->isNegative() ? 'text-red-700' : '' }}">
                                {{ $row['amount']->format() }}
                            </td>
                        </tr>
                    @empty
                        <tr><td class="py-1 pr-4 pl-8 text-slate-500" colspan="2">Sin movimientos</td></tr>
                    @endforelse

                    <tr class="font-semibold">
                        <td class="border-t border-slate-300 px-4 py-1.5">
                            Efectivo neto de {{ mb_strtolower($section['label']) }}
                        </td>
                        <td class="border-t border-slate-300 px-4 py-1.5 text-right font-mono {{ $section['total']->isNegative() ? 'text-red-700' : '' }}">
                            {{ $section['total']->format() }}
                        </td>
                    </tr>
                @endforeach

                <tr class="border-t-2 border-slate-400 font-semibold">
                    <td class="px-4 py-2">VARIACIÓN NETA DEL EFECTIVO</td>
                    <td class="px-4 py-2 text-right font-mono {{ $result['computed_change']->isNegative() ? 'text-red-700' : '' }}">
                        {{ $result['computed_change']->format() }}
                    </td>
                </tr>
                <tr>
                    <td class="px-4 py-1.5 text-slate-700">Efectivo al inicio del período</td>
                    <td class="px-4 py-1.5 text-right font-mono">{{ $result['opening_cash']->format() }}</td>
                </tr>
                <tr class="border-t-2 border-slate-800 text-base font-bold">
                    <td class="px-4 py-3">EFECTIVO AL FINAL DEL PERÍODO</td>
                    <td class="px-4 py-3 text-right font-mono">{{ $result['closing_cash']->format() }}</td>
                </tr>

                @if ($result['reconciled'])
                    <tr class="text-emerald-700">
                        <td colspan="2" class="px-4 py-2 text-right text-xs font-medium">
                            ✓ Cuadra con el saldo real de caja y bancos
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    <div class="mx-auto mt-4 max-w-3xl rounded-xl border border-slate-200 bg-white p-4 text-sm">
        <p class="mb-2 text-xs font-semibold tracking-wider text-slate-500 uppercase">
            Cuentas consideradas efectivo
        </p>
        <ul class="space-y-0.5 text-slate-600">
            @forelse ($result['cash_accounts'] as $account)
                <li class="font-mono text-xs">{{ $account->code }} — {{ $account->name }}</li>
            @empty
                <li class="text-red-700">
                    Ninguna cuenta está marcada como efectivo; el reporte saldrá vacío.
                    Márcalas en el plan de cuentas.
                </li>
            @endforelse
        </ul>
    </div>
</x-report-shell>
