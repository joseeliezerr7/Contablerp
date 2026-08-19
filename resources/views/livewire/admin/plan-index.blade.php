@php
    $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none';
    $dt = 'text-xs font-semibold tracking-wider text-slate-500 uppercase';
@endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Planes del servicio</h2>
            <p class="text-sm text-slate-500">
                Lo que ofrecés en el registro y lo que se asigna en «Cuentas del servicio».
                Cambiar un plan solo afecta a quien contrate de ahí en adelante.
            </p>
        </div>

        <button type="button" wire:click="create"
                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
            Nuevo plan
        </button>
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Plan</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Precio</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Límites</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Módulos</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Suscripciones</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Acciones</th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($plans as $plan)
                    <tr role="row" class="{{ $plan->is_active ? '' : 'opacity-60' }}">
                        <td role="cell" data-label="Plan" class="px-4 py-1.5">
                            <span class="font-medium">{{ $plan->name }}</span>
                            <span class="ml-1 font-mono text-[10px] text-slate-400">{{ $plan->code }}</span>
                            @unless ($plan->is_public)
                                <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600"
                                      title="No sale en el registro público; solo se asigna a mano.">
                                    privado
                                </span>
                            @endunless
                            @if ($plan->description)
                                <span class="block text-xs text-slate-500">{{ $plan->description }}</span>
                            @endif
                        </td>
                        <td role="cell" data-label="Precio" class="px-4 py-1.5 text-right whitespace-nowrap tabular-nums">
                            @if ($plan->isFree())
                                Gratis
                            @else
                                L {{ $plan->priceAmount()->format() }}
                                <span class="block text-[10px] text-slate-400">
                                    {{ $plan->interval === 'yearly' ? 'al año' : 'al mes' }}
                                </span>
                            @endif
                        </td>
                        <td role="cell" data-label="Límites" class="px-4 py-1.5 text-xs text-slate-600">
                            <span class="block">Empresas: {{ $plan->max_companies ?? 'sin límite' }}</span>
                            <span class="block">Usuarios: {{ $plan->max_users ?? 'sin límite' }}</span>
                            <span class="block">Documentos/mes: {{ $plan->max_monthly_documents ?? 'sin límite' }}</span>
                        </td>
                        <td role="cell" data-label="Módulos" class="px-4 py-1.5 text-xs text-slate-600">
                            {{ collect([
                                'Inventario' => $plan->has_inventory,
                                'Tesorería' => $plan->has_treasury,
                                'Activos' => $plan->has_fixed_assets,
                                'Multiempresa' => $plan->has_multi_company,
                            ])->filter()->keys()->join(', ') ?: 'Solo el núcleo' }}
                        </td>
                        <td role="cell" data-label="Suscripciones" class="px-4 py-1.5 text-right tabular-nums">
                            {{ $plan->subscriptions_count }}
                        </td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium
                                         {{ $plan->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                {{ $plan->is_active ? 'Activo' : 'Retirado' }}
                            </span>
                        </td>
                        <td role="cell" data-label="Acciones" class="px-4 py-1.5">
                            <div class="flex flex-wrap justify-end gap-x-3 gap-y-1 text-xs">
                                <button type="button" wire:click="edit({{ $plan->id }})"
                                        class="text-slate-600 underline hover:text-slate-900">Editar</button>
                                {{-- Retirar, no borrar: las suscripciones históricas
                                     lo referencian. --}}
                                <button type="button" wire:click="toggleActive({{ $plan->id }})"
                                        class="underline {{ $plan->is_active ? 'text-amber-700 hover:text-amber-900' : 'text-emerald-700 hover:text-emerald-900' }}">
                                    {{ $plan->is_active ? 'Retirar' : 'Reactivar' }}
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="7" class="px-4 py-8 text-center text-slate-500">
                            No hay planes: nadie puede registrarse.
                            <button type="button" wire:click="create" class="underline">Creá el primero</button>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar plan' : 'Nuevo plan'" onClose="closeForm">
            <form wire:submit="save" class="space-y-4 p-5">
                <div class="grid gap-4 sm:grid-cols-6">
                    <x-field label="Código" for="code" error="code" class="sm:col-span-2">
                        <input id="code" type="text" wire:model="code" autofocus placeholder="pyme"
                               class="{{ $input }} w-full lowercase">
                    </x-field>

                    <x-field label="Nombre" for="name" error="name" class="sm:col-span-4">
                        <input id="name" type="text" wire:model="name" placeholder="PYME" class="{{ $input }} w-full">
                    </x-field>

                    <x-field label="Descripción" for="description" error="description" class="sm:col-span-6"
                             hint="Sale en la tarjeta del registro público.">
                        <input id="description" type="text" wire:model="description" class="{{ $input }} w-full">
                    </x-field>

                    <x-field label="Precio (L)" for="price" error="price" class="sm:col-span-2">
                        <input id="price" type="number" min="0" step="0.01" wire:model="price"
                               class="{{ $input }} w-full text-right">
                    </x-field>

                    <x-field label="Periodicidad" for="interval" error="interval" class="sm:col-span-2">
                        <select id="interval" wire:model="interval" class="{{ $input }} w-full">
                            <option value="monthly">Mensual</option>
                            <option value="yearly">Anual</option>
                        </select>
                    </x-field>

                    <x-field label="Días de prueba" for="trial_days" error="trial_days" class="sm:col-span-2">
                        <input id="trial_days" type="number" min="0" max="365" wire:model="trial_days"
                               class="{{ $input }} w-full text-right">
                    </x-field>
                </div>

                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <p class="mb-1 {{ $dt }}">Límites</p>
                    <p class="mb-3 text-xs text-slate-500">Vacío significa sin límite.</p>

                    <div class="grid gap-4 sm:grid-cols-4">
                        <x-field label="Empresas" for="max_companies" error="max_companies">
                            <input id="max_companies" type="number" min="1" wire:model="max_companies"
                                   class="{{ $input }} w-full text-right">
                        </x-field>
                        <x-field label="Usuarios" for="max_users" error="max_users">
                            <input id="max_users" type="number" min="1" wire:model="max_users"
                                   class="{{ $input }} w-full text-right">
                        </x-field>
                        <x-field label="Sucursales" for="max_branches" error="max_branches">
                            <input id="max_branches" type="number" min="1" wire:model="max_branches"
                                   class="{{ $input }} w-full text-right">
                        </x-field>
                        <x-field label="Documentos/mes" for="max_monthly_documents" error="max_monthly_documents">
                            <input id="max_monthly_documents" type="number" min="1" wire:model="max_monthly_documents"
                                   class="{{ $input }} w-full text-right">
                        </x-field>
                    </div>
                </div>

                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <p class="mb-3 {{ $dt }}">Módulos incluidos</p>

                    <div class="grid gap-2 text-sm sm:grid-cols-2">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model="has_inventory"
                                   class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                            Inventario multi-bodega
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model="has_treasury"
                                   class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                            Tesorería y conciliación
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model="has_fixed_assets"
                                   class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                            Activos fijos
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model="has_multi_company"
                                   class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                            Varias empresas
                        </label>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="is_public"
                               class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                        Sale en el registro público
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="is_active"
                               class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                        Activo
                    </label>
                    <x-field label="Orden" for="sort_order" error="sort_order">
                        <input id="sort_order" type="number" min="0" wire:model="sort_order"
                               class="{{ $input }} w-full text-right">
                    </x-field>
                </div>
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
