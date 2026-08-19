@php
    use App\Livewire\Catalog\CatalogIndex;

    $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none';
@endphp

<div>
    <x-flash />

    <div class="mb-4">
        <h2 class="text-lg font-semibold">Catálogos</h2>
        <p class="text-sm text-slate-500">
            Los datos de referencia que usa el resto del sistema. Se llenan una vez, al
            configurar la empresa.
        </p>
    </div>

    {{-- Pestañas --}}
    <div class="mb-4 flex flex-wrap gap-1 border-b border-slate-200">
        @foreach (CatalogIndex::TABS as $key => $meta)
            <button type="button" wire:click="$set('tab', '{{ $key }}')"
                    class="-mb-px border-b-2 px-4 py-2 text-sm font-medium transition
                           {{ $tab === $key
                              ? 'border-slate-900 text-slate-900'
                              : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}">
                {{ $meta['title'] }}
            </button>
        @endforeach
    </div>

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <p class="text-sm text-slate-500">{{ $config['hint'] }}</p>

        @can('create', $config['model'])
            <button type="button" wire:click="create"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                Nueva {{ $config['singular'] }}
            </button>
        @endcan
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Código</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Nombre</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Acciones</th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($records as $record)
                    <tr role="row" class="{{ $record->is_active ? '' : 'opacity-60' }}">
                        <td role="cell" data-label="Código" class="px-4 py-1.5 font-mono text-xs">{{ $record->code }}</td>
                        <td role="cell" data-label="Nombre" class="px-4 py-1.5 font-medium">
                            {{ $record->name }}
                            @if ($isPriceList && $record->is_default)
                                <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-normal text-slate-600">
                                    predeterminada
                                </span>
                            @endif
                        </td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium
                                         {{ $record->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                {{ $record->is_active ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                        <td role="cell" data-label="Acciones" class="px-4 py-1.5">
                            <div class="flex flex-wrap justify-end gap-x-3 gap-y-1 text-xs">
                                @can('update', $record)
                                    <button type="button" wire:click="edit({{ $record->id }})"
                                            class="text-slate-600 underline hover:text-slate-900">Editar</button>
                                @endcan
                                @can('deactivate', $record)
                                    <button type="button" wire:click="toggleActive({{ $record->id }})"
                                            class="underline {{ $record->is_active ? 'text-amber-700 hover:text-amber-900' : 'text-emerald-700 hover:text-emerald-900' }}">
                                        {{ $record->is_active ? 'Desactivar' : 'Activar' }}
                                    </button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="4" class="px-4 py-8 text-center text-slate-500">
                            Todavía no hay {{ $config['plural'] }}.
                            @can('create', $config['model'])
                                <button type="button" wire:click="create" class="underline">Creá la primera</button>.
                            @endcan
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <details class="mt-4 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">
        <summary class="cursor-pointer font-medium text-slate-700">¿Por qué no se pueden borrar?</summary>
        <p class="mt-2">
            Un producto vendido hace dos años apunta a su unidad y a su categoría, y una
            factura apunta a la lista de precios con la que se cobró. Borrarlas dejaría
            documentos históricos hablando de algo que ya no existe. Desactivarlas las
            saca de los selectores sin tocar lo ya emitido.
        </p>
    </details>

    @if ($showForm)
        <x-modal :title="($editingId ? 'Editar ' : 'Nueva ').$config['singular']" onClose="closeForm">
            <form wire:submit="save" class="space-y-4 p-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Código" for="code" error="code">
                        <input id="code" type="text" wire:model="code" autofocus
                               placeholder="{{ $config['codeHint'] }}"
                               class="{{ $input }} w-full uppercase">
                    </x-field>

                    <x-field label="Nombre" for="name" error="name" class="sm:col-span-2">
                        <input id="name" type="text" wire:model="name" class="{{ $input }} w-full">
                    </x-field>
                </div>

                @if ($isPriceList)
                    <label for="is_default" class="flex items-start gap-2 text-sm">
                        <input id="is_default" type="checkbox" wire:model="is_default"
                               class="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                        <span>
                            Usarla como predeterminada
                            <span class="block text-xs text-slate-500">
                                Es la que trae el formulario de venta. Solo una puede serlo:
                                al marcar esta, la otra deja de serlo.
                            </span>
                        </span>
                    </label>
                @endif

                <label for="is_active" class="flex items-center gap-2 text-sm">
                    <input id="is_active" type="checkbox" wire:model="is_active"
                           class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                    Activa
                </label>
            </form>

            <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                <button type="button" wire:click="closeForm"
                        class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                    Cancelar
                </button>
                <button type="button" wire:click="save"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Guardar
                </button>
            </div>
        </x-modal>
    @endif
</div>
