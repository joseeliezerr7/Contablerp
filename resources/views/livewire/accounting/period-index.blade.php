@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Períodos contables</h2>
            <p class="text-sm text-slate-500">
                Una partida solo puede contabilizarse en un período abierto.
            </p>
        </div>

        @can('accounting.periods.create')
            <form wire:submit="createFiscalYear" class="flex items-end gap-2">
                <label class="text-sm">
                    <span class="mb-1 block font-medium text-slate-700">Nuevo ejercicio</span>
                    <input type="number" wire:model="newYear" min="2000" max="2100" class="{{ $input }} w-28">
                </label>
                <button type="submit"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Crear ejercicio
                </button>
            </form>
        @endcan
    </div>

    <div class="space-y-6">
        @forelse ($fiscalYears as $year)
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-4 py-2">
                    <div>
                        <h3 class="text-sm font-semibold">Ejercicio {{ $year->name }}</h3>
                        <p class="text-xs text-slate-500">
                            {{ $year->starts_on->format('d/m/Y') }} – {{ $year->ends_on->format('d/m/Y') }}
                        </p>
                    </div>
                    <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-700">
                        {{ $year->status->label() }}
                    </span>
                </div>

                <table class="table-stacked w-full text-sm">
                    <thead role="rowgroup" class="border-b border-slate-200 text-left text-xs tracking-wider text-slate-500 uppercase">
                        <tr role="row">
                            <th role="columnheader" class="px-4 py-2 font-semibold">Período</th>
                            <th role="columnheader" class="px-4 py-2 font-semibold">Del</th>
                            <th role="columnheader" class="px-4 py-2 font-semibold">Al</th>
                            <th role="columnheader" class="px-4 py-2 text-right font-semibold">Partidas</th>
                            <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                            <th role="columnheader" class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup" class="divide-y divide-slate-100">
                        @foreach ($year->periods as $period)
                            <tr role="row" class="hover:bg-slate-50">
                                <td role="cell" data-label="Período" class="px-4 py-1.5 font-medium">{{ $period->name }}</td>
                                <td role="cell" data-label="Del" class="px-4 py-1.5 text-slate-600">{{ $period->starts_on->format('d/m/Y') }}</td>
                                <td role="cell" data-label="Al" class="px-4 py-1.5 text-slate-600">{{ $period->ends_on->format('d/m/Y') }}</td>
                                <td role="cell" data-label="Partidas" class="px-4 py-1.5 text-right">{{ $period->journal_entries_count }}</td>
                                <td role="cell" data-label="Estado" class="px-4 py-1.5">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $period->status->badgeClasses() }}">
                                        {{ $period->status->label() }}
                                    </span>
                                </td>
                                <td role="cell" class="px-4 py-1.5 text-right whitespace-nowrap">
                                    @if ($period->status->acceptsPostings())
                                        @can('accounting.periods.close')
                                            <button type="button" wire:click="close({{ $period->id }})"
                                                    wire:confirm="¿Cerrar {{ $period->name }}? Dejará de admitir partidas."
                                                    class="text-xs text-slate-600 underline hover:text-slate-900">
                                                Cerrar
                                            </button>
                                        @endcan
                                    @elseif ($period->status->canReopen())
                                        @can('accounting.periods.reopen')
                                            <button type="button" wire:click="reopen({{ $period->id }})"
                                                    wire:confirm="¿Reabrir {{ $period->name }}?"
                                                    class="text-xs text-amber-700 underline hover:text-amber-900">
                                                Reabrir
                                            </button>
                                        @endcan
                                    @else
                                        <span class="text-xs text-slate-400">Bloqueado</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
                Esta empresa no tiene ejercicios fiscales.
            </div>
        @endforelse
    </div>
</div>
