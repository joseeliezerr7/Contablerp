@php $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Impuestos</h2>
            <p class="text-sm text-slate-500">
                Con qué tasa se factura. La cambia una ley, no un despliegue.
            </p>
        </div>

        @can('create', \App\Domains\Taxation\Models\Tax::class)
            <button type="button" wire:click="create"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                Nuevo impuesto
            </button>
        @endcan
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Código</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Nombre</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Tasa</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">En el precio</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Cobrado (por pagar)</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Pagado (acreditable)</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Acciones</th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($taxes as $tax)
                    <tr role="row" class="hover:bg-slate-50 {{ $tax->is_active ? '' : 'opacity-50' }}">
                        <td role="cell" data-label="Código" class="px-4 py-1.5 font-mono text-xs">{{ $tax->code }}</td>
                        <td role="cell" data-label="Nombre" class="px-4 py-1.5 font-medium">
                            {{ $tax->name }}
                            @if ($tax->is_default)
                                <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-normal text-slate-600">
                                    predeterminado
                                </span>
                            @endif
                        </td>
                        <td role="cell" data-label="Tasa" class="px-4 py-1.5 text-right font-mono whitespace-nowrap">
                            {{ rtrim(rtrim((string) $tax->rate, '0'), '.') }}&nbsp;%
                        </td>
                        <td role="cell" data-label="En el precio" class="px-4 py-1.5 text-xs">
                            {{ $tax->is_included ? 'Incluido' : 'Se suma' }}
                        </td>
                        <td role="cell" data-label="Cobrado (por pagar)" class="px-4 py-1.5 text-xs">
                            {{ $tax->payableAccount?->code ?? '—' }}
                            <span class="block text-slate-500">{{ $tax->payableAccount?->name }}</span>
                        </td>
                        <td role="cell" data-label="Pagado (acreditable)" class="px-4 py-1.5 text-xs">
                            {{ $tax->creditableAccount?->code ?? '—' }}
                            <span class="block text-slate-500">{{ $tax->creditableAccount?->name }}</span>
                        </td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $tax->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                {{ $tax->is_active ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td role="cell" data-label="Acciones" class="px-4 py-1.5">
                            <div class="flex flex-wrap justify-end gap-x-3 gap-y-1 text-xs">
                                @can('update', $tax)
                                    <button type="button" wire:click="edit({{ $tax->id }})"
                                            class="text-slate-600 underline hover:text-slate-900">Editar</button>
                                    <button type="button" wire:click="toggleActive({{ $tax->id }})"
                                            class="{{ $tax->is_active ? 'text-amber-700 hover:text-amber-900' : 'text-emerald-700 hover:text-emerald-900' }} underline">
                                        {{ $tax->is_active ? 'Desactivar' : 'Activar' }}
                                    </button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="8" class="px-4 py-8 text-center text-slate-500">
                            Todavía no hay impuestos configurados.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-xs text-slate-500">
        Un impuesto no se elimina: las facturas emitidas guardan la tasa que se les aplicó pero
        siguen apuntando a él. Cuando el SAR cambia una tasa, se desactiva la vieja y se crea la
        nueva.
    </p>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar impuesto' : 'Nuevo impuesto'" onClose="closeForm">
            <form wire:submit="save">
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Código" for="code" error="code">
                        <input id="code" type="text" wire:model="code" class="{{ $input }} font-mono">
                    </x-field>

                    <x-field label="Nombre" for="name" error="name">
                        <input id="name" type="text" wire:model="name" class="{{ $input }}">
                    </x-field>

                    <x-field label="Tasa (%)" for="rate" error="rate"
                             hint="Cero es válido: una exoneración es un impuesto al 0 %, no la ausencia de impuesto.">
                        <input id="rate" type="text" inputmode="decimal" wire:model="rate"
                               class="{{ $input }} text-right font-mono">
                    </x-field>

                    <x-field label="El precio ya lo incluye" for="is_included" error="is_included"
                             hint="Marcalo para venta al público, donde la etiqueta ya trae el impuesto.">
                        <label class="flex items-center gap-2 text-sm">
                            <input id="is_included" type="checkbox" wire:model="is_included" class="rounded border-slate-300">
                            Incluido en el precio
                        </label>
                    </x-field>

                    <x-field label="Cuenta del impuesto cobrado" for="payable_account_id" error="payable_account_id"
                             class="sm:col-span-2"
                             hint="Lo que le cobrás al cliente y le debés al SAR: un pasivo.">
                        <select id="payable_account_id" wire:model="payable_account_id" class="{{ $input }}">
                            <option value="">Sin asignar</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Cuenta del impuesto acreditable" for="creditable_account_id" error="creditable_account_id"
                             class="sm:col-span-2"
                             hint="Lo que pagás al comprar y podés acreditar: un activo.">
                        <select id="creditable_account_id" wire:model="creditable_account_id" class="{{ $input }}">
                            <option value="">Sin asignar</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Predeterminado" for="is_default" error="is_default"
                             hint="El que se propone en un producto nuevo. Solo puede haber uno.">
                        <label class="flex items-center gap-2 text-sm">
                            <input id="is_default" type="checkbox" wire:model="is_default" class="rounded border-slate-300">
                            Usarlo por defecto
                        </label>
                    </x-field>

                    <x-field label="Estado" for="is_active" error="is_active">
                        <label class="flex items-center gap-2 text-sm">
                            <input id="is_active" type="checkbox" wire:model="is_active" class="rounded border-slate-300">
                            Activo
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
