@php $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex items-center justify-between">
        <div>
            <h2 class="text-lg font-semibold">Bodegas</h2>
            <p class="text-sm text-slate-500">Bodegas de la empresa activa.</p>
        </div>
        <button type="button" wire:click="create"
                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
            Nueva bodega
        </button>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Código</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Nombre</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Sucursal</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($warehouses as $warehouse)
                    <tr role="row" class="hover:bg-slate-50">
                        <td role="cell" data-label="Código" class="px-4 py-2 font-mono text-xs">{{ $warehouse->code }}</td>
                        <td role="cell" data-label="Nombre" class="px-4 py-2 font-medium">
                            {{ $warehouse->name }}
                            @if ($warehouse->is_default)
                                <span class="ml-1 rounded-full bg-slate-900 px-2 py-0.5 text-xs font-medium text-white">Por defecto</span>
                            @endif
                        </td>
                        <td role="cell" data-label="Sucursal" class="px-4 py-2 text-slate-600">{{ $warehouse->branch->name }}</td>
                        <td role="cell" data-label="Estado" class="px-4 py-2">
                            @if ($warehouse->is_active)
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Activa</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Inactiva</span>
                            @endif
                        </td>
                        <td role="cell" class="px-4 py-2 text-right whitespace-nowrap">
                            <button type="button" wire:click="edit({{ $warehouse->id }})"
                                    class="text-sm text-slate-600 underline hover:text-slate-900">
                                Editar
                            </button>
                            @unless ($warehouse->is_default)
                                <button type="button" wire:click="delete({{ $warehouse->id }})"
                                        wire:confirm="¿Eliminar la bodega {{ $warehouse->name }}?"
                                        class="ml-3 text-sm text-red-600 underline hover:text-red-800">
                                    Eliminar
                                </button>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="5" class="px-4 py-8 text-center text-slate-500">
                            Esta empresa no tiene bodegas.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar bodega' : 'Nueva bodega'">
            <form wire:submit="save">
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Código" for="code" error="code">
                        <input id="code" type="text" wire:model="code" autofocus class="{{ $input }}">
                    </x-field>

                    <x-field label="Nombre" for="name" error="name">
                        <input id="name" type="text" wire:model="name" class="{{ $input }}">
                    </x-field>

                    <x-field label="Sucursal" for="branch_id" error="branch_id" class="sm:col-span-2">
                        <select id="branch_id" wire:model="branch_id" class="{{ $input }}">
                            <option value="">Selecciona una sucursal</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model="is_default"
                                   class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                            Bodega por defecto
                        </label>
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model="is_active"
                                   class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                            Bodega activa
                        </label>
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancel"
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
</div>
