@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Régimen de facturación</h2>
            <p class="text-sm text-slate-500">
                Puntos de emisión y autorizaciones (CAI) que entrega el SAR. Sin una autorización
                vigente el sistema no emite facturas.
            </p>
        </div>

        @can('create', App\Domains\Fiscal\Models\FiscalPoint::class)
            <button type="button" wire:click="newPoint"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                Nuevo punto de emisión
            </button>
        @endcan
    </div>

    {{-- Lo primero: qué CAI hay que renovar --}}
    @if ($alerts->isNotEmpty())
        <div class="mb-5 rounded-xl border border-amber-300 bg-amber-50 p-4">
            <p class="text-sm font-semibold text-amber-900">Autorizaciones por renovar</p>
            <ul class="mt-2 space-y-1 text-sm text-amber-900">
                @foreach ($alerts as $alert)
                    <li>
                        <span class="font-medium">{{ $alert->point->prefix() }}</span>
                        · {{ $alert->document_type->label() }}
                        @if ($alert->daysToLimit() < 0)
                            — <strong>venció hace {{ abs($alert->daysToLimit()) }} día(s)</strong>
                        @elseif ($alert->daysToLimit() <= 30)
                            — vence en {{ $alert->daysToLimit() }} día(s)
                            ({{ $alert->limit_date->format('d/m/Y') }})
                        @endif
                        @if ($alert->usedPercent() >= 85)
                            — quedan <strong>{{ $alert->remaining() }}</strong> correlativos
                            de {{ $alert->total() }}
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="mt-2 text-xs text-amber-800">
                Tramitar una autorización nueva toma días. Pedila antes de quedarte sin poder facturar.
            </p>
        </div>
    @endif

    {{-- Puntos de emisión --}}
    <div class="space-y-4">
        @forelse ($points as $point)
            <div class="rounded-xl border border-slate-200 bg-white">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
                    <div>
                        <span class="font-mono text-sm font-semibold">{{ $point->prefix() }}</span>
                        <span class="ml-2 text-sm">{{ $point->name }}</span>
                        <span class="ml-2 text-xs text-slate-500">{{ $point->branch->name }}</span>
                        @unless ($point->is_active)
                            <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Inactivo</span>
                        @endunless
                    </div>

                    <div class="flex items-center gap-3 text-xs">
                        @can('update', $point)
                            <button type="button" wire:click="editPoint({{ $point->id }})"
                                    class="text-slate-600 underline hover:text-slate-900">Editar</button>
                        @endcan
                        @can('create', App\Domains\Fiscal\Models\FiscalAuthorization::class)
                            <button type="button" wire:click="newAuthorization({{ $point->id }})"
                                    class="font-medium text-slate-900 underline">Cargar CAI</button>
                        @endcan
                    </div>
                </div>

                @if ($point->authorizations->isEmpty())
                    <p class="px-4 py-6 text-center text-sm text-slate-500">
                        Todavía no tiene ninguna autorización. Este punto no puede emitir documentos.
                    </p>
                @else
                    <div class="table-stacked-wrap overflow-x-auto">
                        <table class="table-stacked w-full text-sm">
                            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                                <tr role="row">
                                    <th role="columnheader" class="px-4 py-2 font-semibold">Documento</th>
                                    <th role="columnheader" class="px-4 py-2 font-semibold">CAI</th>
                                    <th role="columnheader" class="px-4 py-2 font-semibold">Rango autorizado</th>
                                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Consumo</th>
                                    <th role="columnheader" class="px-4 py-2 font-semibold">Fecha límite</th>
                                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Acciones</th>
                                </tr>
                            </thead>
                            <tbody role="rowgroup" class="divide-y divide-slate-100">
                                @foreach ($point->authorizations as $authorization)
                                    <tr role="row" class="{{ $authorization->status->canEmit() ? '' : 'text-slate-500' }}">
                                        <td role="cell" data-label="Documento" class="px-4 py-1.5">
                                            {{ $authorization->document_type->label() }}
                                            <span class="font-mono text-xs text-slate-500">
                                                (tipo {{ $authorization->document_type_code }})
                                            </span>
                                        </td>
                                        <td role="cell" data-label="CAI" class="px-4 py-1.5 font-mono text-xs">
                                            {{ $authorization->cai }}
                                        </td>
                                        <td role="cell" data-label="Rango" class="px-4 py-1.5 font-mono text-xs whitespace-nowrap">
                                            {{ str_pad((string) $authorization->range_from, 8, '0', STR_PAD_LEFT) }}
                                            –
                                            {{ str_pad((string) $authorization->range_to, 8, '0', STR_PAD_LEFT) }}
                                        </td>
                                        <td role="cell" data-label="Consumo" class="px-4 py-1.5 text-right">
                                            <span class="font-mono">{{ $authorization->used() }}</span>
                                            <span class="text-xs text-slate-500">/ {{ $authorization->total() }}</span>
                                            <span class="block text-xs {{ $authorization->usedPercent() >= 85 ? 'font-medium text-amber-700' : 'text-slate-500' }}">
                                                quedan {{ $authorization->remaining() }}
                                            </span>
                                        </td>
                                        <td role="cell" data-label="Fecha límite" class="px-4 py-1.5 whitespace-nowrap">
                                            {{ $authorization->limit_date->format('d/m/Y') }}
                                            @if ($authorization->status->canEmit())
                                                <span class="block text-xs {{ $authorization->daysToLimit() <= 30 ? 'font-medium text-amber-700' : 'text-slate-500' }}">
                                                    {{ $authorization->daysToLimit() >= 0
                                                        ? $authorization->daysToLimit().' día(s)'
                                                        : 'vencida' }}
                                                </span>
                                            @endif
                                        </td>
                                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $authorization->status->badgeClasses() }}">
                                                {{ $authorization->status->label() }}
                                            </span>
                                        </td>
                                        <td role="cell" data-label="Acciones" class="px-4 py-1.5">
                                            <div class="flex flex-wrap justify-end gap-x-3 gap-y-1 text-xs">
                                                @can('update', $authorization)
                                                    <button type="button" wire:click="editAuthorization({{ $authorization->id }})"
                                                            class="text-slate-600 underline hover:text-slate-900">Corregir</button>
                                                @endcan
                                                @can('replace', $authorization)
                                                    <button type="button" wire:click="confirmRetire({{ $authorization->id }})"
                                                            class="text-amber-700 underline hover:text-amber-900">Dar de baja</button>
                                                @endcan
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-10 text-center text-slate-500">
                No hay puntos de emisión. Registrá el que te asignó el SAR para poder facturar.
            </div>
        @endforelse
    </div>

    {{-- Alta y edición del punto de emisión --}}
    @if ($showingPointForm)
        <x-modal title="Punto de emisión" onClose="resetPointForm">
            <form wire:submit="savePoint">
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Sucursal" for="branch_id" error="branch_id" class="sm:col-span-2">
                        <select id="branch_id" wire:model="branch_id" class="{{ $input }} w-full">
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Código de establecimiento" for="establishment_code" error="establishment_code"
                             hint="Tres dígitos, tal como aparece en tu inscripción.">
                        <input id="establishment_code" type="text" wire:model="establishment_code"
                               maxlength="3" inputmode="numeric" class="{{ $input }} w-full font-mono">
                    </x-field>

                    <x-field label="Punto de emisión" for="emission_point_code" error="emission_point_code"
                             hint="Tres dígitos. Cada caja lleva el suyo.">
                        <input id="emission_point_code" type="text" wire:model="emission_point_code"
                               maxlength="3" inputmode="numeric" class="{{ $input }} w-full font-mono">
                    </x-field>

                    <x-field label="Nombre" for="name" error="name" class="sm:col-span-2">
                        <input id="name" type="text" wire:model="name" class="{{ $input }} w-full">
                    </x-field>

                    <label class="flex items-center gap-2 text-sm sm:col-span-2">
                        <input type="checkbox" wire:model="is_active"
                               class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                        Activo
                    </label>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="resetPointForm"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Guardar
                    </button>
                </div>
            </form>
        </x-modal>
    @endif

    {{-- Carga y corrección de la autorización --}}
    @if ($authorizingPoint)
        <x-modal :title="$editingAuthorization ? 'Corregir la autorización' : 'Cargar autorización del SAR'"
                 onClose="resetAuthorizationForm">
            <form wire:submit="saveAuthorization">
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <p class="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600 sm:col-span-2">
                        Copiá los datos exactamente como vienen en la resolución. El sistema no los inventa
                        y no puede corregirlos después de que la autorización empiece a numerar.
                    </p>

                    <x-field label="Tipo de documento" for="document_type" error="document_type">
                        <select id="document_type" wire:model.live="document_type" class="{{ $input }} w-full">
                            @foreach ($types as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Código del tipo" for="document_type_code" error="document_type_code"
                             hint="Dos dígitos, los que diga la resolución.">
                        <input id="document_type_code" type="text" wire:model="document_type_code"
                               maxlength="2" inputmode="numeric" class="{{ $input }} w-full font-mono">
                    </x-field>

                    <x-field label="CAI" for="cai" error="cai" class="sm:col-span-2">
                        <input id="cai" type="text" wire:model="cai" class="{{ $input }} w-full font-mono uppercase">
                    </x-field>

                    <x-field label="Correlativo inicial" for="range_from" error="range_from">
                        <input id="range_from" type="number" min="1" wire:model="range_from"
                               class="{{ $input }} w-full font-mono">
                    </x-field>

                    <x-field label="Correlativo final" for="range_to" error="range_to">
                        <input id="range_to" type="number" min="1" wire:model="range_to"
                               class="{{ $input }} w-full font-mono">
                    </x-field>

                    <x-field label="Fecha de autorización" for="issued_on" error="issued_on">
                        <input id="issued_on" type="date" wire:model="issued_on" class="{{ $input }} w-full">
                    </x-field>

                    <x-field label="Fecha límite de emisión" for="limit_date" error="limit_date"
                             hint="Pasada esta fecha el documento deja de ser válido.">
                        <input id="limit_date" type="date" wire:model="limit_date" class="{{ $input }} w-full">
                    </x-field>

                    <x-field label="Notas" for="notes" error="notes" class="sm:col-span-2">
                        <textarea id="notes" wire:model="notes" rows="2" class="{{ $input }} w-full"></textarea>
                    </x-field>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="resetAuthorizationForm"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Guardar
                    </button>
                </div>
            </form>
        </x-modal>
    @endif

    {{-- Baja de la autorización --}}
    @if ($retiring)
        <x-modal title="Dar de baja la autorización" onClose="$set('retiring', null)">
            <div class="space-y-3 p-5 text-sm">
                <p>
                    La autorización dejará de emitir documentos. Los correlativos que le queden
                    <strong>no se recuperan</strong>: una autorización nueva empieza donde diga el SAR.
                </p>
                <p class="text-slate-500">
                    Se hace cuando el SAR ya emitió el reemplazo y hay que dejar de usar esta.
                </p>
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                <button type="button" wire:click="$set('retiring', null)"
                        class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                    Cancelar
                </button>
                <button type="button" wire:click="retire"
                        class="rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">
                    Dar de baja
                </button>
            </div>
        </x-modal>
    @endif
</div>
