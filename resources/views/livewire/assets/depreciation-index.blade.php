@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4">
        <h2 class="text-lg font-semibold">Depreciación</h2>
        <p class="text-sm text-slate-500">Una corrida por mes, con una partida por corrida.</p>
    </div>

    {{-- Vista previa: qué se va a depreciar antes de tocar el libro. --}}
    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <label for="period" class="block text-xs font-medium text-slate-600">Mes a depreciar</label>
                <input id="period" type="date" wire:model.live="period" class="{{ $input }} mt-1">
                @error('period') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="text-right">
                <p class="text-xs tracking-wider text-slate-500 uppercase">Se depreciaría</p>
                <p class="font-mono text-2xl font-semibold">{{ $previewTotal->format() }}</p>
                <p class="text-xs text-slate-500">{{ count($preview) }} activo(s)</p>
            </div>

            @can('create', \App\Domains\Assets\Models\DepreciationRun::class)
                <button type="button" wire:click="run"
                        @disabled($alreadyRun || count($preview) === 0)
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40">
                    Generar depreciación
                </button>
            @endcan
        </div>

        @if ($alreadyRun)
            <p class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
                Este mes ya se depreció. Anula la corrida si necesitas rehacerla.
            </p>
        @elseif (count($preview) === 0)
            <p class="mt-3 rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                No hay activos que depreciar este mes. La depreciación arranca el mes siguiente al de
                la compra, y un activo que llegó a su valor residual deja de generar gasto.
            </p>
        @else
            <div class="table-stacked-wrap mt-4 overflow-x-auto rounded-lg border border-slate-200">
                <table class="table-stacked w-full text-sm">
                    <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                        <tr role="row">
                            <th role="columnheader" class="px-3 py-2 font-semibold">Código</th>
                            <th role="columnheader" class="px-3 py-2 font-semibold">Activo</th>
                            <th role="columnheader" class="px-3 py-2 text-right font-semibold">En libros</th>
                            <th role="columnheader" class="px-3 py-2 text-right font-semibold">Cuota del mes</th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup" class="divide-y divide-slate-100">
                        @foreach ($preview as $row)
                            <tr role="row">
                                <td role="cell" data-label="Código" class="px-3 py-1.5 font-mono text-xs">{{ $row['asset']->code }}</td>
                                <td role="cell" data-label="Activo" class="px-3 py-1.5">{{ $row['asset']->name }}</td>
                                <td role="cell" data-label="En libros" class="px-3 py-1.5 text-right font-mono text-slate-600">
                                    {{ $row['asset']->bookValue()->format() }}
                                </td>
                                <td role="cell" data-label="Cuota del mes" class="px-3 py-1.5 text-right font-mono font-semibold">
                                    {{ $row['amount']->format() }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <h3 class="mb-2 text-sm font-semibold text-slate-600">Corridas anteriores</h3>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Número</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Mes</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Activos</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Total</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Acciones</th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($runs as $run)
                    <tr role="row" class="hover:bg-slate-50 {{ $run->isVoided() ? 'opacity-60' : '' }}">
                        <td role="cell" data-label="Número" class="px-4 py-1.5 font-mono text-xs">{{ $run->number }}</td>
                        <td role="cell" data-label="Mes" class="px-4 py-1.5">
                            {{ $run->period_month->translatedFormat('F \d\e Y') }}
                        </td>
                        <td role="cell" data-label="Activos" class="px-4 py-1.5 text-right font-mono">{{ $run->asset_count }}</td>
                        <td role="cell" data-label="Total" class="px-4 py-1.5 text-right font-mono">{{ $run->totalAmount()->format() }}</td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $run->badgeClasses() }}">
                                {{ $run->isVoided() ? 'Anulada' : 'Contabilizada' }}
                            </span>
                        </td>
                        <td role="cell" data-label="Acciones" class="px-4 py-1.5 text-right">
                            <a href="{{ route('assets.depreciation.show', $run->id) }}" wire:navigate
                               class="text-xs font-medium text-slate-700 underline hover:text-slate-900">Ver</a>
                            @can('void', $run)
                                <button type="button" wire:click="confirmVoid({{ $run->id }})"
                                        class="ml-2 text-xs text-red-600 underline hover:text-red-800">Anular</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="6" class="px-4 py-8 text-center text-slate-500">
                            Todavía no se ha depreciado ningún mes.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $runs->links() }}</div>

    @if ($voidingId)
        <x-modal title="Anular corrida" onClose="cancelVoid">
            <form wire:submit="void">
                <div class="space-y-3 p-5">
                    <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        Se revierte la partida y cada activo recupera el acumulado que tenía antes.
                        Solo puede anularse la corrida más reciente: deshacer un mes intermedio dejaría
                        los meses posteriores apoyados en un número que ya no existe.
                    </p>

                    <x-field label="Motivo" for="voidReason" error="voidReason">
                        <textarea id="voidReason" wire:model="voidReason" rows="3"
                                  class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none"></textarea>
                    </x-field>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancelVoid"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Anular
                    </button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
