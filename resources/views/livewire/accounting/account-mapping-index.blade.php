@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Cuentas por módulo</h2>
            <p class="text-sm text-slate-500">
                A qué cuenta del plan va cada cosa que el sistema contabiliza solo.
            </p>
        </div>

        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar una clave…"
               class="{{ $input }} w-64">
    </div>

    @if ($missing !== [])
        <div class="mb-5 rounded-xl border border-amber-300 bg-amber-50 p-4">
            <p class="text-sm font-semibold text-amber-900">
                {{ count($missing) === 1 ? 'Falta una cuenta por asignar' : 'Faltan '.count($missing).' cuentas por asignar' }}
            </p>
            <p class="mt-1 text-xs text-amber-800">
                El módulo que dependa de una de estas fallará al intentar contabilizar, con este
                mismo nombre en el mensaje.
            </p>
            <ul class="mt-2 flex flex-wrap gap-2">
                @foreach ($missing as $key)
                    <li class="rounded-md border border-amber-300 bg-white px-2 py-1 text-xs">
                        {{ $key->label() }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @can('update', App\Domains\Accounting\Models\AccountMapping::class)
        <div class="mb-5 rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs text-slate-600">
            Cambiar una cuenta afecta <strong>lo que se contabilice de aquí en adelante</strong>.
            Lo ya contabilizado no se mueve: cada asiento guardó la cuenta concreta, no la clave.
            Así que el mayor de la cuenta anterior conserva su historia y el de la nueva empieza hoy.
        </div>
    @endcan

    @forelse ($groups as $module => $keys)
        <div class="mb-5 overflow-hidden rounded-xl border border-slate-200 bg-white">
            <div class="border-b border-slate-200 bg-slate-50 px-4 py-2">
                <h3 class="text-xs font-semibold tracking-wider text-slate-500 uppercase">{{ $module }}</h3>
            </div>

            <div class="divide-y divide-slate-100">
                @foreach ($keys as $key)
                    <div class="flex flex-wrap items-start gap-3 px-4 py-3">
                        <div class="min-w-56 flex-1">
                            <p class="text-sm font-medium">{{ $key->label() }}</p>
                            <p class="text-xs text-slate-500">{{ $key->hint() }}</p>
                            <p class="mt-0.5 font-mono text-[11px] text-slate-400">{{ $key->value }}</p>
                        </div>

                        <div class="flex flex-1 flex-wrap items-center justify-end gap-2">
                            <select wire:model="selected.{{ $key->name }}"
                                    @cannot('update', App\Domains\Accounting\Models\AccountMapping::class) disabled @endcannot
                                    class="{{ $input }} w-full max-w-md disabled:bg-slate-100 disabled:text-slate-500">
                                <option value="">Sin asignar</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                                @endforeach
                            </select>

                            @can('update', App\Domains\Accounting\Models\AccountMapping::class)
                                <button type="button" wire:click="assign('{{ $key->name }}')"
                                        class="rounded-md border border-slate-300 px-3 py-2 text-xs font-medium hover:bg-slate-50">
                                    Guardar
                                </button>
                            @endcan
                        </div>

                        @error('selected.'.$key->name)
                            <p class="w-full text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="rounded-xl border border-slate-200 bg-white px-4 py-8 text-center text-slate-500">
            Ninguna clave coincide con «{{ $search }}».
        </div>
    @endforelse
</div>
