<x-report-shell
    title="Balance general"
    description="Situación financiera a la fecha de corte."
    :branches="$branches"
    :show-from="false"
    :warning="$result['balanced'] ? null : 'El balance no cuadra por '.$result['difference']->format().'. Revisa el libro diario antes de usar este reporte.'"
>
    <div class="mx-auto max-w-3xl overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">

                <tr class="bg-slate-100">
                    <td class="px-4 py-2 font-bold tracking-wide uppercase">Activo</td>
                    <td class="px-4 py-2"></td>
                </tr>
                @forelse ($result['assets'] as $group)
                    <tr class="bg-slate-50">
                        <td class="py-1.5 pr-4 pl-6 font-medium">{{ $group['name'] }}</td>
                        <td class="px-4 py-1.5"></td>
                    </tr>
                    @foreach ($group['rows'] as $row)
                        <tr>
                            <td class="py-1 pr-4 pl-10 text-slate-700">{{ $row->label() }}</td>
                            <td class="px-4 py-1 text-right font-mono">{{ $row->statementBalance()->format() }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-medium">
                        <td class="py-1 pr-4 pl-6">Total {{ mb_strtolower($group['name']) }}</td>
                        <td class="border-t border-slate-200 px-4 py-1 text-right font-mono">
                            {{ $group['total']->format() }}
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-2 pr-4 pl-6 text-slate-500" colspan="2">Sin saldos</td></tr>
                @endforelse
                <tr class="border-t-2 border-slate-400 font-bold">
                    <td class="px-4 py-2">TOTAL ACTIVO</td>
                    <td class="px-4 py-2 text-right font-mono">{{ $result['total_assets']->format() }}</td>
                </tr>

                <tr class="bg-slate-100">
                    <td class="px-4 py-2 font-bold tracking-wide uppercase">Pasivo</td>
                    <td class="px-4 py-2"></td>
                </tr>
                @forelse ($result['liabilities'] as $group)
                    <tr class="bg-slate-50">
                        <td class="py-1.5 pr-4 pl-6 font-medium">{{ $group['name'] }}</td>
                        <td class="px-4 py-1.5"></td>
                    </tr>
                    @foreach ($group['rows'] as $row)
                        <tr>
                            <td class="py-1 pr-4 pl-10 text-slate-700">{{ $row->label() }}</td>
                            <td class="px-4 py-1 text-right font-mono">{{ $row->statementBalance()->format() }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-medium">
                        <td class="py-1 pr-4 pl-6">Total {{ mb_strtolower($group['name']) }}</td>
                        <td class="border-t border-slate-200 px-4 py-1 text-right font-mono">
                            {{ $group['total']->format() }}
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-2 pr-4 pl-6 text-slate-500" colspan="2">Sin saldos</td></tr>
                @endforelse
                <tr class="font-semibold">
                    <td class="border-t border-slate-300 px-4 py-1.5">Total pasivo</td>
                    <td class="border-t border-slate-300 px-4 py-1.5 text-right font-mono">
                        {{ $result['total_liabilities']->format() }}
                    </td>
                </tr>

                <tr class="bg-slate-100">
                    <td class="px-4 py-2 font-bold tracking-wide uppercase">Patrimonio</td>
                    <td class="px-4 py-2"></td>
                </tr>
                @foreach ($result['equity'] as $group)
                    @foreach ($group['rows'] as $row)
                        <tr>
                            <td class="py-1 pr-4 pl-10 text-slate-700">{{ $row->label() }}</td>
                            <td class="px-4 py-1 text-right font-mono">{{ $row->statementBalance()->format() }}</td>
                        </tr>
                    @endforeach
                @endforeach
                @if (! $result['profit']->isZero())
                    <tr>
                        <td class="py-1 pr-4 pl-10 text-slate-700">
                            {{ $result['profit']->isNegative() ? 'Pérdida del ejercicio' : 'Utilidad del ejercicio' }}
                        </td>
                        <td class="px-4 py-1 text-right font-mono">{{ $result['profit']->format() }}</td>
                    </tr>
                @endif
                <tr class="font-semibold">
                    <td class="border-t border-slate-300 px-4 py-1.5">Total patrimonio</td>
                    <td class="border-t border-slate-300 px-4 py-1.5 text-right font-mono">
                        {{ $result['total_equity']->plus($result['profit'])->format() }}
                    </td>
                </tr>

                <tr class="border-t-2 border-slate-800 text-base font-bold">
                    <td class="px-4 py-3">TOTAL PASIVO Y PATRIMONIO</td>
                    <td class="px-4 py-3 text-right font-mono">
                        {{ $result['total_liabilities_and_equity']->format() }}
                    </td>
                </tr>

                @if ($result['balanced'])
                    <tr class="text-emerald-700">
                        <td colspan="2" class="px-4 py-2 text-right text-xs font-medium">
                            ✓ Activo = Pasivo + Patrimonio
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
</x-report-shell>
