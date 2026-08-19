@php $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Clientes</h2>
            <p class="text-sm text-slate-500">{{ $customers->total() }} registrados.</p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Código, nombre o RTN…"
                   class="{{ $input }} w-64">
            @can('create', \App\Domains\Partners\Models\Customer::class)
                <button type="button" wire:click="create"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Nuevo cliente
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
                    <th role="columnheader" class="px-4 py-2 font-semibold">RTN</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Lista</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Límite</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Saldo</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($customers as $customer)
                    @php $saldo = $customer->outstandingBalance(); @endphp
                    <tr role="row" class="hover:bg-slate-50 {{ $customer->is_active ? '' : 'opacity-50' }}">
                        <td role="cell" data-label="Código" class="px-4 py-1.5 font-mono text-xs">{{ $customer->code }}</td>
                        <td role="cell" data-label="Nombre" class="px-4 py-1.5 font-medium">{{ $customer->name }}</td>
                        <td role="cell" data-label="RTN" class="px-4 py-1.5 font-mono text-xs">{{ $customer->tax_id ?: '—' }}</td>
                        <td role="cell" data-label="Lista" class="px-4 py-1.5 text-slate-600">{{ $customer->priceList?->name ?? 'Predeterminada' }}</td>
                        <td role="cell" data-label="Límite" class="px-4 py-1.5 text-right font-mono">
                            {{ $customer->hasCredit() ? $customer->creditLimit()->format() : 'Contado' }}
                        </td>
                        <td role="cell" data-label="Saldo" class="px-4 py-1.5 text-right font-mono {{ $saldo->isPositive() ? 'font-semibold' : 'text-slate-400' }}">
                            {{ $saldo->format() }}
                        </td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            @if ($customer->is_active)
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Activo</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Inactivo</span>
                            @endif
                        </td>
                        <td role="cell" class="px-4 py-1.5 text-right whitespace-nowrap">
                            @can('receivables.reports')
                                <a href="{{ route('receivables.statement', ['cliente' => $customer->id]) }}" wire:navigate
                                   class="text-xs text-slate-600 underline hover:text-slate-900">Estado de cuenta</a>
                            @endcan
                            @can('update', $customer)
                                <button type="button" wire:click="edit({{ $customer->id }})"
                                        class="ml-2 text-xs text-slate-600 underline hover:text-slate-900">Editar</button>
                            @endcan
                            @can('delete', $customer)
                                <button type="button" wire:click="delete({{ $customer->id }})"
                                        wire:confirm="¿Eliminar a {{ $customer->name }}?"
                                        class="ml-2 text-xs text-red-600 underline hover:text-red-800">Eliminar</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="8" class="px-4 py-8 text-center text-slate-500">Sin clientes.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $customers->links() }}</div>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar cliente' : 'Nuevo cliente'">
            <form wire:submit="save">
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Código" for="code" error="code">
                        <input id="code" type="text" wire:model="code" class="{{ $input }} font-mono">
                    </x-field>

                    <x-field label="Tipo" for="type" error="type">
                        <select id="type" wire:model="type" class="{{ $input }}">
                            <option value="company">Empresa</option>
                            <option value="individual">Persona natural</option>
                        </select>
                    </x-field>

                    <x-field label="Razón social o nombre" for="name" error="name" class="sm:col-span-2">
                        <input id="name" type="text" wire:model="name" autofocus class="{{ $input }}">
                    </x-field>

                    <x-field label="Nombre comercial" for="trade_name" error="trade_name">
                        <input id="trade_name" type="text" wire:model="trade_name" class="{{ $input }}">
                    </x-field>

                    <x-field label="RTN" for="tax_id" error="tax_id">
                        <input id="tax_id" type="text" wire:model="tax_id" class="{{ $input }} font-mono">
                    </x-field>

                    <x-field label="Correo" for="email" error="email">
                        <input id="email" type="email" wire:model="email" class="{{ $input }}">
                    </x-field>

                    <x-field label="Teléfono" for="phone" error="phone">
                        <input id="phone" type="text" wire:model="phone" class="{{ $input }}">
                    </x-field>

                    <x-field label="Dirección" for="address" error="address" class="sm:col-span-2">
                        <input id="address" type="text" wire:model="address" class="{{ $input }}">
                    </x-field>

                    <x-field label="Lista de precios" for="price_list_id" error="price_list_id">
                        <select id="price_list_id" wire:model="price_list_id" class="{{ $input }}">
                            <option value="">Predeterminada de la empresa</option>
                            @foreach ($priceLists as $list)
                                <option value="{{ $list->id }}">{{ $list->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <div class="grid grid-cols-2 gap-3">
                        <x-field label="Límite de crédito" for="credit_limit" error="credit_limit"
                                 hint="Cero = solo contado.">
                            <input id="credit_limit" type="text" inputmode="decimal" wire:model="credit_limit"
                                   class="{{ $input }} text-right font-mono">
                        </x-field>

                        <x-field label="Días de crédito" for="credit_days" error="credit_days">
                            <input id="credit_days" type="number" min="0" wire:model="credit_days"
                                   class="{{ $input }} text-right">
                        </x-field>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="is_active"
                               class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                        Cliente activo
                    </label>

                    <label class="flex items-start gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="is_walk_in"
                               class="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                        <span>
                            Cliente de mostrador
                            <span class="block text-xs text-slate-500">
                                Es a quien le factura el punto de venta cuando el cliente no se
                                identifica. Solo puede haber uno; marcar otro desmarca al anterior.
                            </span>
                        </span>
                    </label>
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
