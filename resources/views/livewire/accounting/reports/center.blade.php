@php
    $reports = [
        [
            'route' => 'reports.trial-balance',
            'name' => 'Balance de comprobación',
            'description' => 'Saldo inicial, movimiento y saldo final de cada cuenta. Es la verificación de que el libro cuadra.',
        ],
        [
            'route' => 'reports.income-statement',
            'name' => 'Estado de resultados',
            'description' => 'Ingresos menos costos y gastos del período, con utilidad bruta y neta.',
        ],
        [
            'route' => 'reports.balance-sheet',
            'name' => 'Balance general',
            'description' => 'Activo, pasivo y patrimonio a una fecha de corte.',
        ],
        [
            'route' => 'reports.cash-flow',
            'name' => 'Flujo de efectivo',
            'description' => 'Entradas y salidas de caja clasificadas en operación, inversión y financiamiento.',
        ],
        [
            'route' => 'ledger.index',
            'name' => 'Libro mayor',
            'description' => 'Movimientos de una cuenta con saldo acumulado.',
        ],
        [
            'route' => 'journal.index',
            'name' => 'Libro diario',
            'description' => 'Todas las partidas contabilizadas, con sus filtros.',
        ],
    ];
@endphp

<div>
    <x-flash />

    <div class="mb-4">
        <h2 class="text-lg font-semibold">Centro de reportes</h2>
        <p class="text-sm text-slate-500">Estados financieros y libros contables.</p>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($reports as $report)
            <a href="{{ route($report['route']) }}" wire:navigate
               class="block rounded-xl border border-slate-200 bg-white p-4 transition hover:border-slate-400 hover:shadow-sm">
                <h3 class="font-semibold">{{ $report['name'] }}</h3>
                <p class="mt-1 text-sm text-slate-500">{{ $report['description'] }}</p>
            </a>
        @endforeach
    </div>

    @can('accounting.periods.close')
        <div class="mt-8">
            <h3 class="text-base font-semibold">Cierre de ejercicio</h3>
            <p class="mb-3 text-sm text-slate-500">
                Cancela las cuentas de resultado y traslada la utilidad a patrimonio.
                Los saldos de balance continúan en el ejercicio siguiente sin partida de apertura.
            </p>

            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <table class="table-stacked w-full text-sm">
                    <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                        <tr role="row">
                            <th role="columnheader" class="px-4 py-2 font-semibold">Ejercicio</th>
                            <th role="columnheader" class="px-4 py-2 font-semibold">Período</th>
                            <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                            <th role="columnheader" class="px-4 py-2 font-semibold">Situación</th>
                            <th role="columnheader" class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup" class="divide-y divide-slate-100">
                        @foreach ($fiscalYears as $year)
                            @php $problems = $blockers[$year->id] ?? []; @endphp
                            <tr role="row">
                                <td role="cell" data-label="Ejercicio" class="px-4 py-2 font-medium">{{ $year->name }}</td>
                                <td role="cell" data-label="Período" class="px-4 py-2 text-slate-600">
                                    {{ $year->starts_on->format('d/m/Y') }} – {{ $year->ends_on->format('d/m/Y') }}
                                </td>
                                <td role="cell" data-label="Estado" class="px-4 py-2">
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                                        {{ $year->status->label() }}
                                    </span>
                                </td>
                                <td role="cell" data-label="Situación" class="px-4 py-2 text-xs text-slate-600">
                                    @if ($year->status->value !== 'open')
                                        Cerrado el {{ $year->closed_at?->format('d/m/Y') ?? '—' }}
                                    @elseif ($problems === [])
                                        <span class="text-emerald-700">Listo para cerrar</span>
                                    @else
                                        <ul class="list-inside list-disc space-y-0.5">
                                            @foreach ($problems as $problem)
                                                <li>{{ $problem }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>
                                <td role="cell" class="px-4 py-2 text-right">
                                    @if ($year->status->value === 'open' && $problems === [])
                                        <button type="button" wire:click="confirmClose({{ $year->id }})"
                                                class="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800">
                                            Cerrar ejercicio
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endcan

    @if ($closingYearId)
        <x-modal title="Cerrar ejercicio fiscal" onClose="cancelClose">
            <div class="space-y-3 p-5 text-sm">
                <p class="rounded-md bg-amber-50 px-3 py-2 text-amber-800">
                    Esta operación genera las partidas de cierre y cierra todos los períodos del
                    ejercicio. Después no se podrá contabilizar en él sin reabrirlo.
                </p>
                <p class="text-slate-600">
                    Se cancelarán las cuentas de ingresos, costos y gastos contra
                    <strong>Resumen de Resultados</strong>, y el resultado del ejercicio pasará a
                    <strong>Utilidades Retenidas</strong>.
                </p>
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                <button type="button" wire:click="cancelClose"
                        class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                    Cancelar
                </button>
                <button type="button" wire:click="closeYear"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Cerrar ejercicio
                </button>
            </div>
        </x-modal>
    @endif
</div>
