@php $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Retenciones</h2>
            <p class="text-sm text-slate-500">
                Se practican al pagar y al cobrar, no al facturar.
            </p>
        </div>

        @can('create', \App\Domains\Assets\Models\WithholdingType::class)
            <button type="button" wire:click="create"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                Nueva retención
            </button>
        @endcan
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Código</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Nombre</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Tipo</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Tasa</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Ámbito</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Cuenta</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($types as $type)
                    <tr role="row" class="hover:bg-slate-50 {{ $type->is_active ? '' : 'opacity-50' }}">
                        <td role="cell" data-label="Código" class="px-4 py-1.5 font-mono text-xs">{{ $type->code }}</td>
                        <td role="cell" data-label="Nombre" class="px-4 py-1.5 font-medium">{{ $type->name }}</td>
                        <td role="cell" data-label="Tipo" class="px-4 py-1.5 text-slate-600">{{ $type->kind->label() }}</td>
                        <td role="cell" data-label="Tasa" class="px-4 py-1.5 text-right font-mono">
                            {{ rtrim(rtrim($type->rate, '0'), '.') }} %
                        </td>
                        <td role="cell" data-label="Ámbito" class="px-4 py-1.5 text-slate-600">{{ $type->applies_to->label() }}</td>
                        <td role="cell" data-label="Cuenta" class="px-4 py-1.5 text-slate-600">
                            <span class="font-mono text-xs">{{ $type->account->code }}</span>
                            {{ $type->account->name }}
                        </td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            @if ($type->is_active)
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Activa</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Inactiva</span>
                            @endif
                        </td>
                        <td role="cell" class="px-4 py-1.5 text-right whitespace-nowrap">
                            @can('update', $type)
                                <button type="button" wire:click="edit({{ $type->id }})"
                                        class="text-xs text-slate-600 underline hover:text-slate-900">Editar</button>
                            @endcan
                            @can('delete', $type)
                                <button type="button" wire:click="delete({{ $type->id }})"
                                        wire:confirm="¿Eliminar {{ $type->name }}?"
                                        class="ml-2 text-xs text-red-600 underline hover:text-red-800">Eliminar</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="8" class="px-4 py-8 text-center text-slate-500">
                            Todavía no hay retenciones configuradas.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar retención' : 'Nueva retención'" onClose="closeForm">
            <form wire:submit="save">
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Código" for="code" error="code">
                        <input id="code" type="text" wire:model="code" class="{{ $input }} font-mono">
                    </x-field>

                    <x-field label="Nombre" for="name" error="name">
                        <input id="name" type="text" wire:model="name" class="{{ $input }}">
                    </x-field>

                    <x-field label="Impuesto" for="kind" error="kind">
                        <select id="kind" wire:model="kind" class="{{ $input }}">
                            @foreach ($kinds as $kind)
                                <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Porcentaje" for="rate" error="rate">
                        <input id="rate" type="text" inputmode="decimal" wire:model="rate"
                               class="{{ $input }} text-right font-mono">
                    </x-field>

                    <x-field label="Ámbito" for="applies_to" error="applies_to" class="sm:col-span-2">
                        <select id="applies_to" wire:model="applies_to" class="{{ $input }}">
                            @foreach ($scopes as $scope)
                                <option value="{{ $scope->value }}">{{ $scope->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Cuenta contable" for="account_id" error="account_id" class="sm:col-span-2"
                             hint="Lo que retenemos es un pasivo con el fisco; lo que nos retienen, un impuesto pagado por anticipado.">
                        <select id="account_id" wire:model="account_id" class="{{ $input }}">
                            <option value="">Selecciona…</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Estado" for="is_active" error="is_active">
                        <label class="flex items-center gap-2 text-sm">
                            <input id="is_active" type="checkbox" wire:model="is_active" class="rounded border-slate-300">
                            Activa
                        </label>
                    </x-field>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeForm"
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
