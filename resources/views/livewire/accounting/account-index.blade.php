@php $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Plan de cuentas</h2>
            <p class="text-sm text-slate-500">{{ $accounts->count() }} cuentas.</p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Código o nombre…"
                   class="{{ $input }} w-56">
            <select wire:model.live="typeFilter" class="{{ $input }} w-40">
                <option value="">Todos los tipos</option>
                @foreach ($types as $type)
                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                @endforeach
            </select>
            @can('create', \App\Domains\Accounting\Models\Account::class)
                <button type="button" wire:click="create"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Nueva cuenta
                </button>
            @endcan
        </div>
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Código</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Nombre</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Tipo</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Naturaleza</th>
                    <th role="columnheader" class="px-4 py-2 text-center font-semibold">Imputable</th>
                    <th role="columnheader" class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($accounts as $account)
                    <tr role="row" class="hover:bg-slate-50 {{ $account->is_active ? '' : 'opacity-50' }}">
                        <td role="cell" data-label="Código" class="px-4 py-1.5 font-mono text-xs whitespace-nowrap">
                            <span style="padding-left: {{ ($account->level - 1) * 16 }}px">{{ $account->code }}</span>
                        </td>
                        <td role="cell" data-label="Nombre" class="px-4 py-1.5 {{ $account->is_postable ? '' : 'font-semibold' }}">
                            {{ $account->name }}
                            @if ($account->is_system)
                                <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium tracking-wide text-slate-600 uppercase">Sistema</span>
                            @endif
                        </td>
                        <td role="cell" data-label="Tipo" class="px-4 py-1.5 text-slate-600">{{ $account->type->label() }}</td>
                        <td role="cell" data-label="Naturaleza" class="px-4 py-1.5 text-slate-600">{{ $account->nature->label() }}</td>
                        <td role="cell" data-label="Imputable" class="px-4 py-1.5 text-center">
                            @if ($account->is_postable)
                                <span class="text-emerald-600">●</span>
                            @else
                                <span class="text-slate-300">—</span>
                            @endif
                        </td>
                        <td role="cell" class="px-4 py-1.5 text-right whitespace-nowrap">
                            @can('create', \App\Domains\Accounting\Models\Account::class)
                                <button type="button" wire:click="create({{ $account->id }})"
                                        class="text-xs text-slate-500 underline hover:text-slate-900">
                                    Subcuenta
                                </button>
                            @endcan
                            @can('update', $account)
                                <button type="button" wire:click="edit({{ $account->id }})"
                                        class="ml-2 text-xs text-slate-600 underline hover:text-slate-900">
                                    Editar
                                </button>
                            @endcan
                            @can('delete', $account)
                                <button type="button" wire:click="delete({{ $account->id }})"
                                        wire:confirm="¿Eliminar la cuenta {{ $account->code }}?"
                                        class="ml-2 text-xs text-red-600 underline hover:text-red-800">
                                    Eliminar
                                </button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="6" class="px-4 py-8 text-center text-slate-500">
                            No hay cuentas que coincidan con el filtro.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar cuenta' : 'Nueva cuenta'">
            <form wire:submit="save">
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Cuenta padre" for="parent_id" error="parent_id" class="sm:col-span-2">
                        <select id="parent_id" wire:model="parent_id" class="{{ $input }}">
                            <option value="">Sin padre (cuenta de primer nivel)</option>
                            @foreach ($parents as $parent)
                                <option value="{{ $parent->id }}">{{ $parent->code }} — {{ $parent->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Código" for="code" error="code" hint="Debe empezar con el código de su cuenta padre.">
                        <input id="code" type="text" wire:model="code" class="{{ $input }} font-mono">
                    </x-field>

                    <x-field label="Nombre" for="name" error="name">
                        <input id="name" type="text" wire:model="name" class="{{ $input }}">
                    </x-field>

                    <x-field label="Tipo" for="type" error="type">
                        <select id="type" wire:model.live="type" class="{{ $input }}">
                            <option value="">Selecciona…</option>
                            @foreach ($types as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Naturaleza" for="nature" error="nature"
                             hint="Cámbiala solo en contra-cuentas (depreciación acumulada, devoluciones).">
                        <select id="nature" wire:model="nature" class="{{ $input }}">
                            @foreach ($natures as $nature)
                                <option value="{{ $nature->value }}">{{ $nature->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Clasificación de flujo de efectivo" for="cash_flow_class" error="cash_flow_class">
                        <select id="cash_flow_class" wire:model="cash_flow_class" class="{{ $input }}">
                            <option value="">Sin clasificar</option>
                            @foreach ($cashFlowClasses as $class)
                                <option value="{{ $class->value }}">{{ $class->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <div class="space-y-2 self-end text-sm text-slate-700">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model="requires_partner"
                                   class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                            Exige cliente o proveedor
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model="requires_branch"
                                   class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                            Exige sucursal
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model="is_active"
                                   class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                            Cuenta activa
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
