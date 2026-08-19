@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Usuarios</h2>
            <p class="text-sm text-slate-500">
                Quién entra a <strong>{{ $company->legal_name }}</strong> y qué puede hacer.
                Una misma persona puede tener roles distintos en cada empresa.
            </p>
        </div>

        @can('create', App\Models\User::class)
            <button type="button" wire:click="create"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                Invitar usuario
            </button>
        @endcan
    </div>

    {{-- Contraseña temporal, una sola vez --}}
    @if ($temporaryPassword)
        <div class="mb-5 rounded-xl border border-emerald-300 bg-emerald-50 p-4">
            <p class="text-sm font-semibold text-emerald-900">
                Contraseña temporal de {{ $temporaryFor }}
            </p>
            <p class="mt-1 text-xs text-emerald-800">
                Pasásela por el medio que quieras y pedile que la cambie. El sistema guarda solo el
                hash: no puede volver a mostrártela.
            </p>

            <div x-data="{ copied: false }" class="mt-3 flex flex-wrap items-center gap-2">
                <code class="rounded-md border border-emerald-200 bg-white px-3 py-2 font-mono text-base tracking-widest">{{ $temporaryPassword }}</code>

                <button type="button"
                        @click="navigator.clipboard.writeText('{{ $temporaryPassword }}'); copied = true; setTimeout(() => copied = false, 2000)"
                        class="rounded-md border border-emerald-300 bg-white px-3 py-2 text-xs font-medium text-emerald-900 hover:bg-emerald-100">
                    <span x-show="! copied">Copiar</span>
                    <span x-show="copied" x-cloak>Copiado</span>
                </button>

                <button type="button" wire:click="dismissPassword"
                        class="rounded-md px-3 py-2 text-xs text-emerald-900 underline">
                    Ya la anoté
                </button>
            </div>
        </div>
    @endif

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Nombre</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Correo</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Rol</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Sucursal</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Último acceso</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Acciones</th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @foreach ($users as $user)
                    @php
                        $rol = $user->roles->firstWhere('company_id', $company->id)?->name;
                        $sucursal = $branches->firstWhere('id', $user->pivot->branch_id);
                    @endphp
                    <tr role="row" class="{{ $user->is_active ? '' : 'text-slate-400' }}">
                        <td role="cell" data-label="Nombre" class="px-4 py-1.5 font-medium">
                            {{ $user->name }}
                            @if ($user->id === auth()->id())
                                <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-normal text-slate-600">vos</span>
                            @endif
                        </td>
                        <td role="cell" data-label="Correo" class="px-4 py-1.5 text-xs">{{ $user->email }}</td>
                        <td role="cell" data-label="Rol" class="px-4 py-1.5">
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium">
                                {{ $rol ?? 'Sin rol' }}
                            </span>
                        </td>
                        <td role="cell" data-label="Sucursal" class="px-4 py-1.5 text-xs">
                            {{ $sucursal?->name ?? 'Todas' }}
                        </td>
                        <td role="cell" data-label="Último acceso" class="px-4 py-1.5 text-xs whitespace-nowrap">
                            {{ $user->last_login_at?->format('d/m/Y H:i') ?? 'Nunca' }}
                        </td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $user->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                {{ $user->is_active ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td role="cell" data-label="Acciones" class="px-4 py-1.5">
                            <div class="flex flex-wrap justify-end gap-x-3 gap-y-1 text-xs">
                                @can('update', $user)
                                    <button type="button" wire:click="edit({{ $user->id }})"
                                            class="text-slate-600 underline hover:text-slate-900">Editar</button>
                                @endcan
                                @can('resetPassword', $user)
                                    <button type="button" wire:click="confirmReset({{ $user->id }})"
                                            class="text-slate-600 underline hover:text-slate-900">Contraseña</button>
                                @endcan
                                @can('update', $user)
                                    @if ($user->id !== auth()->id())
                                        <button type="button" wire:click="toggleActive({{ $user->id }})"
                                                class="text-amber-700 underline hover:text-amber-900">
                                            {{ $user->is_active ? 'Desactivar' : 'Activar' }}
                                        </button>
                                    @endif
                                @endcan
                                @can('revokeAccess', $user)
                                    <button type="button" wire:click="confirmRevoke({{ $user->id }})"
                                            class="text-red-600 underline hover:text-red-800">Quitar acceso</button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Qué puede hacer cada rol --}}
    <details class="mt-5 rounded-xl border border-slate-200 bg-white p-4 text-sm">
        <summary class="cursor-pointer font-medium">Qué puede hacer cada rol</summary>

        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($permissionsByRole as $nombre => $permisos)
                <div class="rounded-md border border-slate-200 p-3">
                    <p class="text-sm font-semibold">{{ $nombre }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ count($permisos) }} permisos</p>
                </div>
            @endforeach
        </div>

        <p class="mt-3 text-xs text-slate-500">
            Los roles vienen definidos por el sistema y son iguales en todas las empresas. La
            segregación está pensada así: el Cajero factura y cobra pero no anula; el Bodeguero
            captura ajustes pero no los aprueba; el Auditor lo ve todo y no toca nada.
        </p>
    </details>

    {{-- Alta y edición --}}
    @if ($showingForm)
        <x-modal :title="$editingId ? 'Editar usuario' : 'Invitar usuario'" onClose="cancel">
            <form wire:submit="save">
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Nombre" for="name" error="name">
                        <input id="name" type="text" wire:model="name" class="{{ $input }} w-full">
                    </x-field>

                    <x-field label="Correo electrónico" for="email" error="email"
                             :hint="$editingId ? 'El correo no se cambia: es la identidad de la persona en todo el sistema.' : 'Con este correo entra al sistema.'">
                        <input id="email" type="email" wire:model="email" class="{{ $input }} w-full"
                               @disabled($editingId !== null)>
                    </x-field>

                    <x-field label="Rol" for="role" error="role">
                        <select id="role" wire:model="role" class="{{ $input }} w-full">
                            @foreach ($roles as $nombre)
                                <option value="{{ $nombre }}">{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Sucursal" for="branchId" error="branchId"
                             hint="Dejalo vacío para que trabaje en todas.">
                        <select id="branchId" wire:model="branchId" class="{{ $input }} w-full">
                            <option value="">Todas las sucursales</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    @unless ($editingId)
                        <p class="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600 sm:col-span-2">
                            Si el correo ya existe en tu cuenta, no se crea otro usuario: se le da acceso
                            a esta empresa con el rol que elijas. Si es nuevo, el sistema genera una
                            contraseña temporal y te la muestra una sola vez.
                        </p>
                    @endunless
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

    {{-- Quitar acceso --}}
    @if ($confirmingRevoke)
        <x-modal title="Quitar el acceso" onClose="cancel">
            <div class="space-y-3 p-5 text-sm">
                <p>
                    Ya no podrá entrar a <strong>{{ $company->legal_name }}</strong>, pero su cuenta,
                    sus documentos y su rastro en la bitácora <strong>quedan intactos</strong>: un
                    usuario no se borra porque las facturas que emitió lo referencian.
                </p>
                <p class="text-slate-500">
                    Si además trabaja en otra empresa tuya, ahí sigue entrando.
                </p>
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                <button type="button" wire:click="cancel"
                        class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                    Cancelar
                </button>
                <button type="button" wire:click="revoke"
                        class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                    Quitar acceso
                </button>
            </div>
        </x-modal>
    @endif

    {{-- Contraseña temporal --}}
    @if ($confirmingReset)
        <x-modal title="Generar contraseña temporal" onClose="cancel">
            <div class="space-y-3 p-5 text-sm">
                <p>
                    Se reemplaza su contraseña actual por una nueva que el sistema te muestra
                    <strong>una sola vez</strong>. La anterior deja de servir de inmediato.
                </p>
                <p class="text-slate-500">
                    Nadie —ni vos— puede ver la contraseña de otra persona: lo que se guarda es un hash.
                </p>
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                <button type="button" wire:click="cancel"
                        class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                    Cancelar
                </button>
                <button type="button" wire:click="resetPassword"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Generar
                </button>
            </div>
        </x-modal>
    @endif
</div>
