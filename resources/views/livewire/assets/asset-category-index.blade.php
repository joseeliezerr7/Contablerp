@php
    $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none';
@endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Categorías de activo</h2>
            <p class="text-sm text-slate-500">
                Cuánto dura cada tipo de activo y contra qué cuentas se registra.
                Sin al menos una, no se puede dar de alta un activo fijo.
            </p>
        </div>

        @can('create', App\Domains\Assets\Models\FixedAssetCategory::class)
            <button type="button" wire:click="create"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                Nueva categoría
            </button>
        @endcan
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Código</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Nombre</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Vida útil</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Cuentas</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Activos</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Acciones</th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($categories as $category)
                    <tr role="row" class="{{ $category->is_active ? '' : 'opacity-60' }}">
                        <td role="cell" data-label="Código" class="px-4 py-1.5 font-mono text-xs">{{ $category->code }}</td>
                        <td role="cell" data-label="Nombre" class="px-4 py-1.5 font-medium">{{ $category->name }}</td>
                        <td role="cell" data-label="Vida útil" class="px-4 py-1.5 text-right whitespace-nowrap tabular-nums">
                            {{ $category->useful_life_months }} meses
                            <span class="block text-[10px] text-slate-400">
                                {{ round($category->useful_life_months / 12, 1) }} años
                            </span>
                        </td>
                        <td role="cell" data-label="Cuentas" class="px-4 py-1.5 text-xs text-slate-600">
                            <span class="block">Activo: {{ $category->assetAccount?->code ?? '—' }}</span>
                            <span class="block">Gasto: {{ $category->depreciationAccount?->code ?? '—' }}</span>
                            <span class="block">Acumulada: {{ $category->accumulatedAccount?->code ?? '—' }}</span>
                        </td>
                        <td role="cell" data-label="Activos" class="px-4 py-1.5 text-right tabular-nums">
                            {{ $category->assets_count }}
                        </td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium
                                         {{ $category->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                {{ $category->is_active ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                        <td role="cell" data-label="Acciones" class="px-4 py-1.5">
                            <div class="flex flex-wrap justify-end gap-x-3 gap-y-1 text-xs">
                                @can('update', $category)
                                    <button type="button" wire:click="edit({{ $category->id }})"
                                            class="text-slate-600 underline hover:text-slate-900">Editar</button>
                                    <button type="button" wire:click="toggleActive({{ $category->id }})"
                                            class="underline {{ $category->is_active ? 'text-amber-700 hover:text-amber-900' : 'text-emerald-700 hover:text-emerald-900' }}">
                                        {{ $category->is_active ? 'Desactivar' : 'Activar' }}
                                    </button>
                                @endcan
                                {{-- Solo se borra la que nunca se usó: con activos
                                     colgando, cada uno quedaría sin saber contra
                                     qué cuenta deprecia. --}}
                                @can('delete', $category)
                                    <button type="button" wire:click="delete({{ $category->id }})"
                                            wire:confirm="¿Eliminar esta categoría?"
                                            class="text-red-600 underline hover:text-red-800">Eliminar</button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="7" class="px-4 py-8 text-center text-slate-500">
                            No hay categorías todavía, así que el módulo de activos fijos no se puede usar.
                            @can('create', App\Domains\Assets\Models\FixedAssetCategory::class)
                                <button type="button" wire:click="create" class="underline">Creá la primera</button>.
                            @endcan
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar categoría' : 'Nueva categoría'" onClose="closeForm">
            <form wire:submit="save" class="space-y-4 p-5">
                <div class="grid gap-4 sm:grid-cols-6">
                    <x-field label="Código" for="code" error="code" class="sm:col-span-2">
                        <input id="code" type="text" wire:model="code" autofocus placeholder="MOB, EQC…"
                               class="{{ $input }} w-full uppercase">
                    </x-field>

                    <x-field label="Nombre" for="name" error="name" class="sm:col-span-4">
                        <input id="name" type="text" wire:model="name" placeholder="Mobiliario y equipo de oficina"
                               class="{{ $input }} w-full">
                    </x-field>

                    <x-field label="Vida útil (meses)" for="useful_life_months" error="useful_life_months"
                             hint="Lo que dura menos de un año es gasto del período, no activo."
                             class="sm:col-span-2">
                        <input id="useful_life_months" type="number" min="12" max="1200" step="1"
                               wire:model="useful_life_months" class="{{ $input }} w-full">
                    </x-field>
                </div>

                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <p class="mb-3 text-xs font-semibold tracking-wider text-slate-500 uppercase">
                        Cuentas contra las que se registra
                    </p>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-field label="Del activo" for="asset_account_id" error="asset_account_id"
                                 hint="Donde entra el bien al comprarlo.">
                            <select id="asset_account_id" wire:model="asset_account_id" class="{{ $input }} w-full">
                                <option value="">Elegí una cuenta…</option>
                                @foreach ($assetAccounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                                @endforeach
                            </select>
                        </x-field>

                        <x-field label="Gasto del mes" for="depreciation_account_id" error="depreciation_account_id"
                                 hint="La cuota que se lleva a resultados.">
                            <select id="depreciation_account_id" wire:model="depreciation_account_id" class="{{ $input }} w-full">
                                <option value="">Elegí una cuenta…</option>
                                @foreach ($expenseAccounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                                @endforeach
                            </select>
                        </x-field>

                        <x-field label="Depreciación acumulada" for="accumulated_account_id" error="accumulated_account_id"
                                 hint="La que resta del activo en el balance.">
                            <select id="accumulated_account_id" wire:model="accumulated_account_id" class="{{ $input }} w-full">
                                <option value="">Elegí una cuenta…</option>
                                @foreach ($assetAccounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                                @endforeach
                            </select>
                        </x-field>
                    </div>
                </div>

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
