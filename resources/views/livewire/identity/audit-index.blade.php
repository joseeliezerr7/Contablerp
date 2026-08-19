@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Bitácora</h2>
            <p class="text-sm text-slate-500">
                Quién hizo qué y cuándo. No se puede editar ni borrar: un registro que se corrige
                no sirve para auditar.
            </p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Motivo, tipo o IP…"
                   class="{{ $input }} w-48">
            <input type="date" wire:model.live="from" class="{{ $input }}">
            <input type="date" wire:model.live="to" class="{{ $input }}">

            <select wire:model.live="userFilter" class="{{ $input }}">
                <option value="">Todo el personal</option>
                @foreach ($people as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>

            <select wire:model.live="moduleFilter" class="{{ $input }}">
                <option value="">Todos los módulos</option>
                @foreach ($modules as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>

            <select wire:model.live="eventFilter" class="{{ $input }}">
                <option value="">Todo lo que pasó</option>
                @foreach ($events as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Cuándo</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Quién</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Qué pasó</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Módulo</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Detalle</th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($logs as $log)
                    <tr role="row" class="hover:bg-slate-50">
                        <td role="cell" data-label="Cuándo" class="px-4 py-1.5 text-xs whitespace-nowrap">
                            {{ $log->created_at->format('d/m/Y H:i') }}
                        </td>
                        <td role="cell" data-label="Quién" class="px-4 py-1.5">
                            {{-- Sin usuario: lo hizo un comando programado, no una persona. --}}
                            {{ $log->user?->name ?? 'El sistema' }}
                        </td>
                        <td role="cell" data-label="Qué pasó" class="px-4 py-1.5">
                            {{ ucfirst($narrator->event($log->event)) }}
                            <span class="text-slate-600">{{ $narrator->subject($log) }}</span>
                            @if ($log->reason)
                                <span class="block text-xs text-slate-500">Motivo: {{ $log->reason }}</span>
                            @endif
                        </td>
                        <td role="cell" data-label="Módulo" class="px-4 py-1.5 text-xs text-slate-600">
                            {{ $narrator->module($log->module) }}
                        </td>
                        <td role="cell" data-label="Detalle" class="px-4 py-1.5">
                            <div class="flex justify-end text-xs">
                                <button type="button" wire:click="show({{ $log->id }})"
                                        class="text-slate-600 underline hover:text-slate-900">Ver</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="5" class="px-4 py-8 text-center text-slate-500">
                            @if ($search !== '' || $moduleFilter !== '' || $eventFilter !== '' || $userFilter !== '' || $from !== '' || $to !== '')
                                Ningún movimiento con esos filtros.
                                <button type="button" wire:click="clearFilters" class="underline">Quitarlos</button>
                            @else
                                Todavía no hay movimientos registrados.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        {{ $logs->links() }}
    </div>

    @if ($detail)
        <x-modal title="Detalle del movimiento" onClose="close">
            <div class="space-y-4 p-5 text-sm">
                <p>
                    <strong>{{ $detail->user?->name ?? 'El sistema' }}</strong>
                    {{ $narrator->event($detail->event) }}
                    {{ $narrator->subject($detail) }}
                    el {{ $detail->created_at->format('d/m/Y') }}
                    a las {{ $detail->created_at->format('H:i') }}.
                </p>

                @if ($detail->reason)
                    <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                        <span class="text-xs font-semibold text-slate-500 uppercase">Motivo</span>
                        <p class="mt-0.5">{{ $detail->reason }}</p>
                    </div>
                @endif

                @php $changes = $narrator->changes($detail); @endphp

                @if ($changes !== [])
                    <div>
                        <span class="text-xs font-semibold text-slate-500 uppercase">Qué cambió</span>
                        <div class="mt-1 overflow-x-auto rounded-md border border-slate-200">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-left text-xs text-slate-500 uppercase">
                                    <tr>
                                        <th class="px-3 py-1.5 font-semibold">Campo</th>
                                        <th class="px-3 py-1.5 font-semibold">Antes</th>
                                        <th class="px-3 py-1.5 font-semibold">Después</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($changes as $change)
                                        <tr>
                                            <td class="px-3 py-1.5 font-medium">{{ $change['field'] }}</td>
                                            <td class="px-3 py-1.5 text-slate-500">{{ $change['from'] ?? '—' }}</td>
                                            <td class="px-3 py-1.5">{{ $change['to'] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                <dl class="grid grid-cols-2 gap-3 border-t border-slate-200 pt-3 text-xs">
                    <div>
                        <dt class="font-semibold text-slate-500 uppercase">Registro</dt>
                        <dd class="mt-0.5">{{ ucfirst($narrator->subjectType($detail->auditable_type)) }} #{{ $detail->auditable_id }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-500 uppercase">Desde qué equipo</dt>
                        <dd class="mt-0.5 font-mono">{{ $detail->ip_address ?? '—' }}</dd>
                    </div>
                    @if ($detail->user_agent)
                        <div class="col-span-2">
                            <dt class="font-semibold text-slate-500 uppercase">Navegador</dt>
                            <dd class="mt-0.5 break-all text-slate-600">{{ $detail->user_agent }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                <button type="button" wire:click="close"
                        class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                    Cerrar
                </button>
            </div>
        </x-modal>
    @endif
</div>
