@php $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex items-center justify-between">
        <div>
            <h2 class="text-lg font-semibold">Empresas</h2>
            <p class="text-sm text-slate-500">Empresas a las que tienes acceso.</p>
        </div>
        <button type="button" wire:click="create"
                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
            Nueva empresa
        </button>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Razón social</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Nombre comercial</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">RTN</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Moneda</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($companies as $company)
                    <tr role="row" class="hover:bg-slate-50">
                        <td role="cell" data-label="Razón social" class="px-4 py-2 font-medium">{{ $company->legal_name }}</td>
                        <td role="cell" data-label="Nombre comercial" class="px-4 py-2 text-slate-600">{{ $company->trade_name ?: '—' }}</td>
                        <td role="cell" data-label="RTN" class="px-4 py-2 font-mono text-xs">{{ $company->tax_id }}</td>
                        <td role="cell" data-label="Moneda" class="px-4 py-2">{{ $company->currency_code }}</td>
                        <td role="cell" data-label="Estado" class="px-4 py-2">
                            @if ($company->is_active)
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Activa</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Inactiva</span>
                            @endif
                        </td>
                        <td role="cell" class="px-4 py-2 text-right">
                            <button type="button" wire:click="edit({{ $company->id }})"
                                    class="text-sm text-slate-600 underline hover:text-slate-900">
                                Editar
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="6" class="px-4 py-8 text-center text-slate-500">
                            Aún no tienes empresas registradas.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar empresa' : 'Nueva empresa'">
            <form wire:submit="save">
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Razón social" for="legal_name" error="legal_name" class="sm:col-span-2">
                        <input id="legal_name" type="text" wire:model="legal_name" autofocus class="{{ $input }}">
                    </x-field>

                    <x-field label="Nombre comercial" for="trade_name" error="trade_name">
                        <input id="trade_name" type="text" wire:model="trade_name" class="{{ $input }}">
                    </x-field>

                    <x-field label="RTN" for="tax_id" error="tax_id">
                        <input id="tax_id" type="text" wire:model="tax_id" class="{{ $input }}">
                    </x-field>

                    <x-field label="Dirección" for="address" error="address">
                        <input id="address" type="text" wire:model="address" class="{{ $input }}">
                    </x-field>

                    <x-field label="Teléfono" for="phone" error="phone">
                        <input id="phone" type="text" wire:model="phone" class="{{ $input }}">
                    </x-field>

                    <x-field label="Correo" for="email" error="email">
                        <input id="email" type="email" wire:model="email" class="{{ $input }}">
                    </x-field>

                    <x-field label="Moneda" for="currency_code" error="currency_code" hint="Código ISO de 3 letras.">
                        <input id="currency_code" type="text" maxlength="3" wire:model="currency_code"
                               class="{{ $input }} uppercase">
                    </x-field>

                    <x-field label="Inicio del ejercicio fiscal" for="fiscal_year_start_month"
                             error="fiscal_year_start_month">
                        <select id="fiscal_year_start_month" wire:model="fiscal_year_start_month" class="{{ $input }}">
                            @foreach (range(1, 12) as $month)
                                <option value="{{ $month }}">
                                    {{ ucfirst(\Carbon\Carbon::create(null, $month)->locale('es')->monthName) }}
                                </option>
                            @endforeach
                        </select>
                    </x-field>

                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="is_active"
                               class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                        Empresa activa
                    </label>
                </div>

                @unless ($editingId)
                    <p class="mx-5 mb-4 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        Se creará automáticamente la sucursal <strong>Casa Matriz</strong> y la
                        <strong>Bodega Principal</strong>.
                    </p>
                @endunless

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
