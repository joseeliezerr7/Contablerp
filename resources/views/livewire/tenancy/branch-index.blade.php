@php $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex items-center justify-between">
        <div>
            <h2 class="text-lg font-semibold">Sucursales</h2>
            <p class="text-sm text-slate-500">Sucursales de la empresa activa.</p>
        </div>
        <button type="button" wire:click="create"
                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
            Nueva sucursal
        </button>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Código</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Nombre</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Teléfono</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Bodegas</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($branches as $branch)
                    <tr role="row" class="hover:bg-slate-50">
                        <td role="cell" data-label="Código" class="px-4 py-2 font-mono text-xs">{{ $branch->code }}</td>
                        <td role="cell" data-label="Nombre" class="px-4 py-2 font-medium">
                            {{ $branch->name }}
                            @if ($branch->is_main)
                                <span class="ml-1 rounded-full bg-slate-900 px-2 py-0.5 text-xs font-medium text-white">Casa matriz</span>
                            @endif
                        </td>
                        <td role="cell" data-label="Teléfono" class="px-4 py-2 text-slate-600">{{ $branch->phone ?: '—' }}</td>
                        <td role="cell" data-label="Bodegas" class="px-4 py-2">{{ $branch->warehouses_count }}</td>
                        <td role="cell" data-label="Estado" class="px-4 py-2">
                            @if ($branch->is_active)
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Activa</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Inactiva</span>
                            @endif
                        </td>
                        <td role="cell" class="px-4 py-2 text-right whitespace-nowrap">
                            <button type="button" wire:click="edit({{ $branch->id }})"
                                    class="text-sm text-slate-600 underline hover:text-slate-900">
                                Editar
                            </button>
                            @unless ($branch->is_main)
                                <button type="button" wire:click="delete({{ $branch->id }})"
                                        wire:confirm="¿Eliminar la sucursal {{ $branch->name }}?"
                                        class="ml-3 text-sm text-red-600 underline hover:text-red-800">
                                    Eliminar
                                </button>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="6" class="px-4 py-8 text-center text-slate-500">
                            Esta empresa no tiene sucursales.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar sucursal' : 'Nueva sucursal'">
            <form wire:submit="save">
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Código" for="code" error="code">
                        <input id="code" type="text" wire:model="code" autofocus class="{{ $input }}">
                    </x-field>

                    <x-field label="Nombre" for="name" error="name">
                        <input id="name" type="text" wire:model="name" class="{{ $input }}">
                    </x-field>

                    <x-field label="Dirección" for="address" error="address" class="sm:col-span-2">
                        <input id="address" type="text" wire:model="address" class="{{ $input }}">
                    </x-field>

                    <x-field label="Teléfono" for="phone" error="phone">
                        <input id="phone" type="text" wire:model="phone" class="{{ $input }}">
                    </x-field>

                    <div class="space-y-2 self-end">
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model="is_main"
                                   class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                            Casa matriz
                        </label>
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model="is_active"
                                   class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                            Sucursal activa
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
