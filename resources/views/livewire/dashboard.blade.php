@php
    use App\Livewire\Dashboard;
    use App\Support\Money;

    $card = 'rounded-xl border border-slate-200 bg-white shadow-sm';

    // Rampa ordinal azul, un solo tono claro→oscuro: más viejo el saldo, más
    // oscuro el tramo. Validada contra el blanco de la tarjeta —no contra el
    // gris del ejemplo— con scripts/validate_palette.js --ordinal.
    $agingRamp = ['#86b6ef', '#5598e7', '#2a78d6', '#1c5cab', '#104281'];

    // Un solo tono para las ventas: una serie no lleva leyenda ni paleta
    // categórica, el título ya dice qué se está viendo.
    $salesHue = '#2a78d6';

    $statusInk = [
        'critical' => 'border-red-200 bg-red-50 text-red-900',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-900',
        'info' => 'border-sky-200 bg-sky-50 text-sky-900',
    ];
@endphp

<div class="space-y-5">
    <x-flash />

    {{-- Encabezado --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold tracking-tight">{{ $company->displayName() }}</h2>
            <p class="text-sm text-slate-500">
                {{ mb_convert_case($today->translatedFormat('F Y'), MB_CASE_TITLE) }} ·
                RTN {{ $company->tax_id }} · {{ $company->currency_code }}
            </p>
        </div>

        @can('sales.invoices.create')
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('pos') }}" wire:navigate
                   class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Abrir el POS
                </a>
                <a href="{{ route('sales.create') }}" wire:navigate
                   class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium hover:bg-slate-50">
                    Nueva factura
                </a>
            </div>
        @endcan
    </div>

    {{-- Avisos que hay que atender --}}
    @if ($alerts !== [])
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($alerts as $alert)
                <a @if ($alert['route']) href="{{ $alert['route'] }}" wire:navigate @endif
                   class="block rounded-xl border px-4 py-3 text-sm transition hover:brightness-[0.98] {{ $statusInk[$alert['level']] }}">
                    <div class="flex items-start gap-2">
                        {{-- Icono además del color: el estado nunca se comunica solo con el tono. --}}
                        <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            @if ($alert['level'] === 'info')
                                <path d="M10 2a8 8 0 100 16 8 8 0 000-16zm1 12H9v-5h2v5zm0-7H9V5h2v2z"/>
                            @else
                                <path d="M9.1 2.6a1 1 0 011.8 0l7 12.6a1 1 0 01-.9 1.5H3a1 1 0 01-.9-1.5l7-12.6zM11 13H9v2h2v-2zm0-6H9v5h2V7z"/>
                            @endif
                        </svg>
                        <div>
                            <p class="font-semibold">{{ $alert['title'] }}</p>
                            <p class="mt-0.5 opacity-90">{{ $alert['detail'] }}</p>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif

    {{-- Indicadores --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @if ($sales)
            <div class="{{ $card }} p-5">
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase">Ventas del mes</p>
                <p class="mt-2 text-3xl font-semibold">L {{ $sales['total']->format() }}</p>
                <p class="mt-1 text-xs text-slate-500">
                    @if ($sales['change'] === null)
                        {{ $sales['count'] }} {{ $sales['count'] === 1 ? 'factura' : 'facturas' }} · sin mes anterior con qué comparar
                    @else
                        <span class="font-semibold {{ $sales['change'] >= 0 ? 'text-emerald-700' : 'text-red-700' }}">
                            {{ $sales['change'] >= 0 ? '↑' : '↓' }} {{ abs($sales['change']) }}%
                        </span>
                        vs. mes anterior · {{ $sales['count'] }} {{ $sales['count'] === 1 ? 'factura' : 'facturas' }}
                    @endif
                </p>
            </div>
        @endif

        @if ($receivables)
            <a href="{{ route('receivables.aging') }}" wire:navigate class="{{ $card }} block p-5 transition hover:border-slate-300">
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase">Por cobrar</p>
                <p class="mt-2 text-3xl font-semibold">L {{ $receivables['total']->format() }}</p>
                <p class="mt-1 text-xs {{ $receivables['overdue']->isZero() ? 'text-slate-500' : 'text-red-700' }}">
                    @if ($receivables['overdue']->isZero())
                        Nada vencido
                    @else
                        L {{ $receivables['overdue']->format() }} vencido en
                        {{ $receivables['overdueCount'] }}
                        {{ $receivables['overdueCount'] === 1 ? 'factura' : 'facturas' }}
                    @endif
                </p>
            </a>
        @endif

        @if ($payables)
            <a href="{{ route('payables.aging') }}" wire:navigate class="{{ $card }} block p-5 transition hover:border-slate-300">
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase">Por pagar</p>
                <p class="mt-2 text-3xl font-semibold">L {{ $payables['total']->format() }}</p>
                <p class="mt-1 text-xs {{ $payables['overdue']->isZero() ? 'text-slate-500' : 'text-amber-700' }}">
                    @if ($payables['overdue']->isZero())
                        Nada vencido
                    @else
                        L {{ $payables['overdue']->format() }} vencido en
                        {{ $payables['overdueCount'] }}
                        {{ $payables['overdueCount'] === 1 ? 'documento' : 'documentos' }}
                    @endif
                </p>
            </a>
        @endif

        @if ($profit)
            <div class="{{ $card }} p-5">
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase">Resultado del mes</p>
                <p class="mt-2 text-3xl font-semibold {{ $profit['net']->isNegative() ? 'text-red-700' : '' }}">
                    L {{ $profit['net']->format() }}
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    Ingresos L {{ $profit['income']->format() }} · costos y gastos L {{ $profit['expense']->format() }}
                </p>
            </div>
        @endif
    </div>

    {{-- Gráficas --}}
    <div class="grid gap-4 lg:grid-cols-3">
        @if ($salesByMonth !== [])
            @php
                $peak = Dashboard::peak(array_column($salesByMonth, 'total'));
                $peakFloat = (float) $peak->toString();
                // Escala redondeada hacia arriba para que el eje diga números
                // limpios en vez del máximo exacto de la serie.
                $step = $peakFloat <= 0 ? 1 : 10 ** (floor(log10($peakFloat)) - 1) * 5;
                $ceiling = $peakFloat <= 0 ? 1 : ceil($peakFloat / $step) * $step;
            @endphp

            <div class="{{ $card }} p-5 lg:col-span-2">
                <div class="mb-1 flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-semibold">Ventas facturadas por mes</h3>
                    <p class="text-xs text-slate-500">Últimos 12 meses · lempiras</p>
                </div>

                {{-- Sin leyenda: una sola serie, el título ya la nombra. --}}
                <div class="mt-4 flex gap-3">
                    {{-- Eje de valores, con cifras tabulares para que alineen --}}
                    <div class="flex w-16 shrink-0 flex-col justify-between py-0.5 text-right text-[10px] text-slate-500 tabular-nums">
                        <span>{{ number_format($ceiling) }}</span>
                        <span>{{ number_format($ceiling / 2) }}</span>
                        <span>0</span>
                    </div>

                    <div class="relative min-w-0 flex-1">
                        {{-- Rejilla: capilar, sólida, recesiva --}}
                        <div class="pointer-events-none absolute inset-0 flex flex-col justify-between">
                            <div class="h-px bg-slate-200"></div>
                            <div class="h-px bg-slate-200"></div>
                            <div class="h-px bg-slate-300"></div>
                        </div>

                        <div class="relative flex h-40 items-end gap-[2px]">
                            @foreach ($salesByMonth as $i => $month)
                                @php
                                    $value = (float) $month['total']->toString();
                                    $pct = $ceiling > 0 ? $value / $ceiling * 100 : 0;
                                    $isPeak = $value > 0 && $value >= $peakFloat;
                                @endphp
                                <div class="group relative flex h-full flex-1 items-end justify-center"
                                     title="{{ $month['month'] }}: L {{ $month['total']->format() }}">
                                    {{-- Columna: ≤24px, punta redondeada de 4px, cuadrada en la base --}}
                                    <div class="w-full max-w-[24px] rounded-t-[4px]"
                                         style="height: {{ max($pct, $value > 0 ? 1.5 : 0) }}%; background-color: {{ $salesHue }}; {{ $isPeak ? '' : 'opacity: .55;' }}"
                                         @if ($value <= 0) hidden @endif></div>

                                    {{-- Etiqueta directa solo en el máximo: un número en cada
                                         columna no se lee. El resto lo dice el eje y el título. --}}
                                    @if ($isPeak)
                                        <span class="absolute -top-4 left-1/2 -translate-x-1/2 text-[10px] font-semibold whitespace-nowrap text-slate-700">
                                            {{ $month['total']->format(0) }}
                                        </span>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        {{--
                            En un teléfono la columna mide 19px y «Sep.» mide 21:
                            los doce meses se atropellan. Se oculta el *texto* de
                            los intermedios, no el div, para que las doce columnas
                            sigan existiendo y cada etiqueta quede debajo de su
                            barra. El mes actual siempre se muestra.
                        --}}
                        <div class="mt-1.5 flex gap-[2px]">
                            @foreach ($salesByMonth as $i => $month)
                                {{-- Sin `overflow-hidden`: recortaría «Sep.» a
                                     «Se». La etiqueta se centra sobre su barra y
                                     desborda sin daño hacia las celdas vacías de
                                     al lado, que es cómo funciona un eje con
                                     etiquetas salteadas. --}}
                                <div class="min-w-0 flex-1 text-center text-[10px] whitespace-nowrap text-slate-500">
                                    <span @class(['hidden sm:inline' => $i % 3 !== 0 && $i !== count($salesByMonth) - 1])>
                                        {{ $month['label'] }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if ($aging !== [])
            @php
                $agingTotal = Money::sum(array_column($aging, 'amount'));
                $agingFloat = (float) $agingTotal->toString();
            @endphp

            <div class="{{ $card }} p-5">
                <h3 class="text-sm font-semibold">Antigüedad de lo que te deben</h3>
                <p class="text-xs text-slate-500">Al {{ $today->format('d/m/Y') }}</p>

                @if ($agingFloat <= 0)
                    <p class="mt-6 text-sm text-slate-500">No hay saldos pendientes de cobro.</p>
                @else
                    {{-- Barra apilada: 2px de superficie separando los tramos --}}
                    <div class="mt-4 flex h-3 gap-[2px] overflow-hidden rounded-full">
                        @foreach ($aging as $i => $bucket)
                            @php $pct = (float) $bucket['amount']->toString() / $agingFloat * 100; @endphp
                            @if ($pct > 0)
                                <div style="width: {{ $pct }}%; background-color: {{ $agingRamp[$i] }}"
                                     title="{{ $bucket['label'] }}: L {{ $bucket['amount']->format() }}"></div>
                            @endif
                        @endforeach
                    </div>

                    {{-- Leyenda: obligatoria con dos o más tramos. El texto va en
                         tinta, el color lo carga el punto de al lado. --}}
                    <ul class="mt-4 space-y-1.5 text-xs">
                        @foreach ($aging as $i => $bucket)
                            <li class="flex items-center justify-between gap-2">
                                <span class="flex min-w-0 items-center gap-2">
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $agingRamp[$i] }}"></span>
                                    <span class="truncate text-slate-600">{{ $bucket['label'] }}</span>
                                </span>
                                <span class="shrink-0 font-medium tabular-nums">{{ $bucket['amount']->format() }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-3 flex justify-between border-t border-slate-200 pt-2 text-xs font-semibold">
                        <span>Total</span>
                        <span class="tabular-nums">L {{ $agingTotal->format() }}</span>
                    </div>
                @endif
            </div>
        @endif
    </div>

    {{-- Efectivo y últimas facturas --}}
    <div class="grid gap-4 lg:grid-cols-3">
        @if ($cashAccounts !== [])
            <div class="{{ $card }} p-5">
                <h3 class="text-sm font-semibold">Caja y bancos</h3>
                {{-- La fecha va explícita: el saldo corta hoy, y una partida
                     fechada mañana no cuenta todavía. Sin decirlo, este número
                     no cuadra contra un balance de comprobación pedido al cierre
                     del mes, y quien lo compare va a pensar que uno está mal. --}}
                <p class="text-xs text-slate-500">Saldo según libros al {{ $today->format('d/m/Y') }}</p>

                <ul class="mt-3 divide-y divide-slate-100 text-sm">
                    @foreach ($cashAccounts as $account)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span class="min-w-0">
                                <span class="block truncate">{{ $account['name'] }}</span>
                                <span class="font-mono text-[10px] text-slate-400">{{ $account['code'] }}</span>
                            </span>
                            <span class="shrink-0 font-medium tabular-nums {{ $account['balance']->isNegative() ? 'text-red-700' : '' }}">
                                {{ $account['balance']->format() }}
                            </span>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-2 flex justify-between border-t border-slate-200 pt-2 text-sm font-semibold">
                    <span>Total</span>
                    <span class="tabular-nums">L {{ Money::sum(array_column($cashAccounts, 'balance'))->format() }}</span>
                </div>
            </div>
        @endif

        @if ($latestInvoices !== [])
            <div class="{{ $card }} p-5 lg:col-span-2">
                <div class="mb-3 flex items-baseline justify-between gap-2">
                    <h3 class="text-sm font-semibold">Últimas facturas emitidas</h3>
                    <a href="{{ route('sales.index') }}" wire:navigate class="text-xs text-slate-600 underline hover:text-slate-900">
                        Ver todas
                    </a>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($latestInvoices as $invoice)
                                <tr>
                                    <td class="py-2 pr-3 font-mono text-xs whitespace-nowrap">{{ $invoice['number'] ?? '—' }}</td>
                                    <td class="max-w-0 truncate py-2 pr-3">{{ $invoice['customer'] }}</td>
                                    <td class="py-2 pr-3 text-xs whitespace-nowrap text-slate-500">{{ $invoice['date']->format('d/m/Y') }}</td>
                                    <td class="py-2 text-right font-medium tabular-nums whitespace-nowrap">{{ $invoice['total']->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    {{-- Alguien sin ningún permiso de lectura no ve una pantalla vacía sin explicación. --}}
    @if (! $sales && ! $receivables && ! $payables && ! $profit && $cashAccounts === [] && $alerts === [])
        <div class="{{ $card }} p-8 text-center">
            <p class="font-medium text-slate-700">Nada que mostrar todavía</p>
            <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                Tu rol no incluye permisos de consulta sobre ventas, cobros, pagos ni tesorería.
                Si necesitás ver estos indicadores, pedíselo a un administrador de la empresa.
            </p>
        </div>
    @endif
</div>
