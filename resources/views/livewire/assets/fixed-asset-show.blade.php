@php
    $card = 'rounded-xl border border-slate-200 bg-white shadow-sm';
    $dt = 'text-xs font-semibold tracking-wider text-slate-500 uppercase';

    $costo = (float) $asset->costAmount()->toString();
    $depreciado = (float) $asset->accumulated()->toString();
    $avance = $costo > 0 ? min(100, round($depreciado / $costo * 100)) : 0;
@endphp

<div class="space-y-5">
    <x-flash />

    {{-- Encabezado --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('assets.index') }}" wire:navigate class="text-xs text-slate-500 underline hover:text-slate-900">
                ← Volver a activos fijos
            </a>
            <h2 class="mt-1 flex flex-wrap items-center gap-2 text-xl font-semibold tracking-tight">
                {{ $asset->code }} — {{ $asset->name }}
                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $asset->status->badgeClasses() }}">
                    {{ $asset->status->label() }}
                </span>
            </h2>
            <p class="text-sm text-slate-500">
                {{ $asset->category?->name }} · comprado el {{ $asset->acquired_on->format('d/m/Y') }}
            </p>
        </div>

        @can('update', $asset)
            <a href="{{ route('assets.index') }}" wire:navigate
               class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium hover:bg-slate-50">
                Editar en la lista
            </a>
        @endcan
    </div>

    @if ($asset->isDisposed())
        <div class="rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm">
            <p class="font-semibold">Activo dado de baja</p>
            <p class="mt-0.5 text-slate-600">
                Se dio de baja el {{ $asset->disposed_on?->format('d/m/Y') }}
                @if ($asset->disposal_amount !== null)
                    por L {{ \App\Support\Money::of($asset->disposal_amount)->format() }}
                @endif
                y ya no deprecia. Se conserva porque su historia sigue en el libro.
            </p>
        </div>
    @endif

    {{-- Los cuatro números --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="{{ $card }} p-5">
            <p class="{{ $dt }}">Costo de adquisición</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums">L {{ $asset->costAmount()->format() }}</p>
        </div>
        <div class="{{ $card }} p-5">
            <p class="{{ $dt }}">Depreciado a la fecha</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums">L {{ $asset->accumulated()->format() }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ $avance }}% del costo</p>
        </div>
        <div class="{{ $card }} p-5">
            <p class="{{ $dt }}">Valor en libros</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums">L {{ $asset->bookValue()->format() }}</p>
        </div>
        <div class="{{ $card }} p-5">
            <p class="{{ $dt }}">Cuota mensual</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums">L {{ $asset->monthlyQuota()->format() }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ $asset->useful_life_months }} meses de vida útil</p>
        </div>
    </div>

    {{-- Avance de la depreciación. Una sola magnitud contra su límite: un
         medidor, no una gráfica. --}}
    <div class="{{ $card }} p-5">
        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2 text-sm">
            <span class="font-semibold">Avance de la depreciación</span>
            <span class="text-slate-500">
                Depreciado hasta
                {{ $asset->depreciated_through
                    ? mb_convert_case($asset->depreciated_through->translatedFormat('F Y'), MB_CASE_TITLE)
                    : 'todavía no' }}
            </span>
        </div>

        <div class="h-3 overflow-hidden rounded-full bg-slate-100">
            <div class="h-full rounded-full bg-slate-900" style="width: {{ $avance }}%"></div>
        </div>

        <dl class="mt-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
            <div>
                <dt class="text-slate-500">Valor de rescate</dt>
                <dd class="font-medium tabular-nums">{{ $asset->salvageValue()->format() }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Falta por depreciar</dt>
                <dd class="font-medium tabular-nums">{{ $asset->remainingDepreciable()->format() }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Cuotas aplicadas</dt>
                <dd class="font-medium tabular-nums">{{ $lines->count() }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Sucursal</dt>
                <dd class="font-medium">{{ $asset->branch?->name ?? '—' }}</dd>
            </div>
        </dl>
    </div>

    {{-- Ficha y cuentas --}}
    <div class="grid gap-4 lg:grid-cols-2">
        <div class="{{ $card }} p-5">
            <h3 class="mb-3 text-sm font-semibold">Ficha</h3>
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="{{ $dt }}">Categoría</dt>
                    <dd class="mt-0.5">{{ $asset->category?->label() ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="{{ $dt }}">Número de serie</dt>
                    <dd class="mt-0.5 font-mono text-xs">{{ $asset->serial_number ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="{{ $dt }}">Ubicación</dt>
                    <dd class="mt-0.5">{{ $asset->location ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="{{ $dt }}">Empieza a depreciar</dt>
                    {{-- El mes siguiente al de la compra: es la convención del
                         motor, y verla escrita evita la consulta de siempre. --}}
                    <dd class="mt-0.5">
                        {{ mb_convert_case($asset->acquired_on->copy()->addMonth()->translatedFormat('F Y'), MB_CASE_TITLE) }}
                    </dd>
                </div>
                @if ($asset->description)
                    <div class="sm:col-span-2">
                        <dt class="{{ $dt }}">Descripción</dt>
                        <dd class="mt-0.5">{{ $asset->description }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        <div class="{{ $card }} p-5">
            <h3 class="mb-3 text-sm font-semibold">Cuentas contra las que se registra</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Del activo</dt>
                    <dd class="text-right">
                        {{ $asset->category?->assetAccount?->name ?? '—' }}
                        <span class="block font-mono text-[10px] text-slate-400">{{ $asset->category?->assetAccount?->code }}</span>
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Gasto del mes</dt>
                    <dd class="text-right">
                        {{ $asset->category?->depreciationAccount?->name ?? '—' }}
                        <span class="block font-mono text-[10px] text-slate-400">{{ $asset->category?->depreciationAccount?->code }}</span>
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Depreciación acumulada</dt>
                    <dd class="text-right">
                        {{ $asset->category?->accumulatedAccount?->name ?? '—' }}
                        <span class="block font-mono text-[10px] text-slate-400">{{ $asset->category?->accumulatedAccount?->code }}</span>
                    </dd>
                </div>
            </dl>
            <p class="mt-3 border-t border-slate-100 pt-2 text-xs text-slate-500">
                Salen de la categoría. Cambiar la categoría del activo cambia
                contra qué cuentas depreciará de aquí en adelante.
            </p>
        </div>
    </div>

    {{-- La historia, que es lo que no se podía ver --}}
    <div class="{{ $card }} overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-3">
            <h3 class="text-sm font-semibold">Depreciación mes a mes</h3>
            <p class="text-xs text-slate-500">Cada cuota que se le aplicó, de la más reciente a la más vieja.</p>
        </div>

        <div class="table-stacked-wrap overflow-x-auto">
            <table class="table-stacked w-full text-sm">
                <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                    <tr role="row">
                        <th role="columnheader" class="px-4 py-2 font-semibold">Mes</th>
                        <th role="columnheader" class="px-4 py-2 font-semibold">Corrida</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Cuota</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Acumulado</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Quedó en libros</th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    @forelse ($lines as $line)
                        @php $anulada = $line->run?->isVoided(); @endphp
                        <tr role="row" class="{{ $anulada ? 'opacity-50' : '' }}">
                            <td role="cell" data-label="Mes" class="px-4 py-1.5 whitespace-nowrap">
                                {{ $line->run
                                    ? mb_convert_case($line->run->period_month->translatedFormat('F Y'), MB_CASE_TITLE)
                                    : '—' }}
                            </td>
                            <td role="cell" data-label="Corrida" class="px-4 py-1.5 font-mono text-xs">
                                @if ($line->run)
                                    <a href="{{ route('assets.depreciation.show', $line->run->id) }}" wire:navigate
                                       class="underline hover:text-slate-900">{{ $line->run->number }}</a>
                                    @if ($anulada)
                                        <span class="ml-1 rounded bg-red-50 px-1.5 py-0.5 text-[10px] font-medium text-red-700">anulada</span>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td role="cell" data-label="Cuota" class="px-4 py-1.5 text-right font-medium tabular-nums">
                                {{ $line->amountMoney()->format() }}
                            </td>
                            <td role="cell" data-label="Acumulado" class="px-4 py-1.5 text-right tabular-nums">
                                {{ $line->accumulatedAfter()->format() }}
                            </td>
                            <td role="cell" data-label="Quedó en libros" class="px-4 py-1.5 text-right tabular-nums">
                                {{ $line->bookValueAfter()->format() }}
                            </td>
                        </tr>
                    @empty
                        <tr role="row">
                            <td role="cell" colspan="5" class="px-4 py-8 text-center text-slate-500">
                                Todavía no se le ha aplicado ninguna cuota.
                                La depreciación arranca el mes siguiente al de la compra.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
