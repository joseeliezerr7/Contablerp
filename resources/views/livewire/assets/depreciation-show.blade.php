@php
    $card = 'rounded-xl border border-slate-200 bg-white shadow-sm';
    $dt = 'text-xs font-semibold tracking-wider text-slate-500 uppercase';

    $total = (float) $run->totalAmount()->toString();
@endphp

<div class="space-y-5">
    <x-flash />

    {{-- Encabezado --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('assets.depreciation.index') }}" wire:navigate class="text-xs text-slate-500 underline hover:text-slate-900">
                ← Volver a depreciación
            </a>
            <h2 class="mt-1 flex flex-wrap items-center gap-2 text-xl font-semibold tracking-tight">
                {{ $run->number }}
                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $run->badgeClasses() }}">
                    {{ $run->isVoided() ? 'Anulada' : 'Contabilizada' }}
                </span>
            </h2>
            <p class="text-sm text-slate-500">
                {{ mb_convert_case($run->period_month->translatedFormat('F Y'), MB_CASE_TITLE) }} ·
                contabilizada el {{ $run->posted_on->format('d/m/Y') }}
            </p>
        </div>

        <p class="text-right">
            <span class="{{ $dt }}">Gasto del mes</span>
            <span class="block text-2xl font-semibold tabular-nums">L {{ $run->totalAmount()->format() }}</span>
            <span class="block text-xs text-slate-500">
                {{ $run->lines->count() }} {{ $run->lines->count() === 1 ? 'activo' : 'activos' }}
            </span>
        </p>
    </div>

    @if ($run->isVoided())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
            <p class="font-semibold">Corrida anulada</p>
            <p class="mt-0.5">
                Su partida se revirtió y la depreciación acumulada de cada activo volvió
                atrás. Se conserva para que la historia del activo no quede con huecos.
            </p>
        </div>
    @endif

    {{-- Por categoría, antes de activo por activo --}}
    @if ($byCategory->isNotEmpty())
        <div class="{{ $card }} p-5">
            <h3 class="mb-3 text-sm font-semibold">En qué se fue</h3>

            <ul class="space-y-2.5">
                @foreach ($byCategory as $nombre => $fila)
                    @php
                        $importe = (float) $fila['total']->toString();
                        $pct = $total > 0 ? $importe / $total * 100 : 0;
                    @endphp
                    <li>
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="min-w-0 truncate">
                                {{ $nombre }}
                                <span class="text-xs text-slate-500">
                                    · {{ $fila['count'] }} {{ $fila['count'] === 1 ? 'activo' : 'activos' }}
                                </span>
                            </span>
                            <span class="shrink-0 font-medium tabular-nums">{{ $fila['total']->format() }}</span>
                        </div>
                        {{-- Barra de magnitud, un solo tono: es cuánto pesa cada
                             categoría dentro del mismo total, no series distintas. --}}
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full" style="width: {{ $pct }}%; background-color: #2a78d6"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Activo por activo: el desglose que no existía --}}
    <div class="{{ $card }} overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-3">
            <h3 class="text-sm font-semibold">Activo por activo</h3>
            <p class="text-xs text-slate-500">Lo que se le cargó a cada uno y cómo quedó después.</p>
        </div>

        <div class="table-stacked-wrap overflow-x-auto">
            <table class="table-stacked w-full text-sm">
                <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                    <tr role="row">
                        <th role="columnheader" class="px-4 py-2 font-semibold">Activo</th>
                        <th role="columnheader" class="px-4 py-2 font-semibold">Categoría</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Cuota</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Acumulado</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Quedó en libros</th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    @forelse ($run->lines->sortBy(fn ($l) => $l->asset?->code) as $line)
                        <tr role="row">
                            <td role="cell" data-label="Activo" class="px-4 py-1.5">
                                @if ($line->asset)
                                    <a href="{{ route('assets.show', $line->asset->id) }}" wire:navigate
                                       class="underline hover:text-slate-900">{{ $line->asset->code }}</a>
                                    <span class="block text-xs text-slate-500">{{ $line->asset->name }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td role="cell" data-label="Categoría" class="px-4 py-1.5 text-xs text-slate-600">
                                {{ $line->asset?->category?->name ?? '—' }}
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
                                Esta corrida no tocó ningún activo.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-3">
            <dl class="flex w-full max-w-xs justify-between text-base font-semibold">
                <dt>Total</dt>
                <dd class="tabular-nums">L {{ $run->totalAmount()->format() }}</dd>
            </dl>
        </div>
    </div>

    {{-- El asiento --}}
    @if ($entry)
        <div class="{{ $card }} flex flex-wrap items-center justify-between gap-3 p-5">
            <div>
                <h3 class="text-sm font-semibold">Partida contable</h3>
                <p class="text-sm text-slate-500">
                    {{ $entry->number }} · {{ $entry->date->format('d/m/Y') }} · {{ $entry->concept }}
                </p>
            </div>
            @can('view', $entry)
                <a href="{{ route('journal.edit', $entry->id) }}" wire:navigate
                   class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium hover:bg-slate-50">
                    Ver la partida
                </a>
            @endcan
        </div>
    @endif
</div>
