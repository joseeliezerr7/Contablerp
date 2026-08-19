@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Tokens de API</h2>
            <p class="text-sm text-slate-500">
                Credenciales para que otros programas lean y escriban en
                <strong>{{ $company->legal_name }}</strong>: cada token actúa sobre esta empresa
                y solo sobre esta.
            </p>
        </div>

        @can('create', App\Domains\Api\Models\ApiToken::class)
            <button type="button" wire:click="create"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                Emitir token
            </button>
        @endcan
    </div>

    {{-- El secreto, una sola vez --}}
    @if ($plainToken)
        <div class="mb-5 rounded-xl border border-emerald-300 bg-emerald-50 p-4">
            <p class="text-sm font-semibold text-emerald-900">
                Copialo ahora: no se puede volver a ver
            </p>
            <p class="mt-1 text-xs text-emerald-800">
                Lo que se guarda es un hash. Si lo perdés, hay que revocar este token y emitir otro.
            </p>

            <div x-data="{ copied: false }" class="mt-3 flex flex-wrap items-center gap-2">
                <code class="flex-1 overflow-x-auto rounded-md border border-emerald-200 bg-white px-3 py-2 font-mono text-xs">{{ $plainToken }}</code>

                <button type="button"
                        @click="navigator.clipboard.writeText($el.previousElementSibling.textContent.trim()); copied = true; setTimeout(() => copied = false, 2000)"
                        class="rounded-md border border-emerald-300 bg-white px-3 py-2 text-xs font-medium text-emerald-900 hover:bg-emerald-100">
                    <span x-show="! copied">Copiar</span>
                    <span x-show="copied" x-cloak>Copiado</span>
                </button>

                <button type="button" wire:click="dismissSecret"
                        class="rounded-md px-3 py-2 text-xs text-emerald-900 underline">
                    Ya lo guardé
                </button>
            </div>
        </div>
    @endif

    {{-- Cómo se usa --}}
    <details class="mb-5 rounded-xl border border-slate-200 bg-white p-4 text-sm">
        <summary class="cursor-pointer font-medium">Cómo se usa la API</summary>

        <div class="mt-3 space-y-3 text-slate-600">
            <p>
                El token va en la cabecera <code class="font-mono">Authorization</code>. La API vive
                en <code class="font-mono">/api/v1</code> y responde siempre JSON.
            </p>

            <pre class="overflow-x-auto rounded-md bg-slate-900 p-3 font-mono text-xs text-slate-100">curl {{ url('/api/v1/me') }} \
  -H "Authorization: Bearer TU_TOKEN" \
  -H "Accept: application/json"</pre>

            <p class="font-medium text-slate-700">Endpoints</p>

            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <tbody class="divide-y divide-slate-100">
                        @foreach ([
                            ['GET', '/me', 'Empresa y alcances del token', '—'],
                            ['GET', '/products', 'Catálogo, con ?search= y ?barcode=', 'catalog:read'],
                            ['GET', '/products/{id}/stock', 'Existencia por bodega', 'inventory:read'],
                            ['GET', '/customers', 'Clientes, con ?search=', 'customers:read'],
                            ['POST', '/customers', 'Crear un cliente', 'customers:write'],
                            ['GET', '/customers/{id}/receivables', 'Saldo y antigüedad', 'receivables:read'],
                            ['GET', '/sales', 'Facturas, con ?from= ?to= ?status=', 'sales:read'],
                            ['POST', '/sales', 'Emitir una factura', 'sales:write'],
                            ['POST', '/sales/{id}/void', 'Anular una factura', 'sales:write'],
                        ] as [$method, $path, $desc, $scope])
                            <tr>
                                <td class="py-1.5 pr-3 font-mono font-semibold">{{ $method }}</td>
                                <td class="py-1.5 pr-3 font-mono">{{ $path }}</td>
                                <td class="py-1.5 pr-3">{{ $desc }}</td>
                                <td class="py-1.5 font-mono text-slate-500">{{ $scope }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="rounded-md bg-slate-50 px-3 py-2 text-xs">
                <strong>Al emitir facturas</strong>, mandá la cabecera
                <code class="font-mono">Idempotency-Key</code> con un identificador tuyo del pedido.
                Si la petición se reintenta, devuelve la misma factura en vez de emitir otra y gastar
                dos correlativos del SAR.
            </p>

            <p class="text-xs">
                Límite: 120 peticiones por minuto y por token. Los importes salen como cadena con dos
                decimales, para que no se pierda el centavo al parsearlos.
            </p>
        </div>
    </details>

    {{-- Tokens --}}
    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Nombre</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Dueño</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Alcances</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Último uso</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Vence</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Acciones</th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($tokens as $token)
                    <tr role="row" class="{{ $token->isUsable() ? '' : 'text-slate-400' }}">
                        <td role="cell" data-label="Nombre" class="px-4 py-1.5 font-medium">{{ $token->name }}</td>
                        <td role="cell" data-label="Dueño" class="px-4 py-1.5">
                            {{ $token->tokenable?->name ?? '—' }}
                        </td>
                        <td role="cell" data-label="Alcances" class="px-4 py-1.5">
                            <div class="flex flex-wrap gap-1">
                                @foreach ($token->scopes() as $scope)
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs">{{ $scope }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td role="cell" data-label="Último uso" class="px-4 py-1.5 text-xs whitespace-nowrap">
                            @if ($token->last_used_at)
                                {{ $token->last_used_at->format('d/m/Y H:i') }}
                                <span class="block text-slate-500">{{ $token->last_used_ip }}</span>
                            @else
                                <span class="text-slate-500">Nunca</span>
                            @endif
                        </td>
                        <td role="cell" data-label="Vence" class="px-4 py-1.5 text-xs whitespace-nowrap">
                            {{ $token->expires_at?->format('d/m/Y') ?? 'Sin vencimiento' }}
                        </td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $token->statusClasses() }}">
                                {{ $token->statusLabel() }}
                            </span>
                        </td>
                        <td role="cell" data-label="Acciones" class="px-4 py-1.5">
                            <div class="flex flex-wrap justify-end gap-x-3 text-xs">
                                @can('revoke', $token)
                                    <button type="button" wire:click="confirmRevoke({{ $token->id }})"
                                            class="text-red-600 underline hover:text-red-800">Revocar</button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="7" class="px-4 py-8 text-center text-slate-500">
                            No hay tokens. Emití uno para conectar otro programa con esta empresa.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Emisión --}}
    @if ($showingForm)
        <x-modal title="Emitir un token" onClose="cancel">
            <form wire:submit="save">
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Nombre" for="name" error="name" class="sm:col-span-2"
                             hint="Para qué es. «Tienda en línea», «App de repartidores».">
                        <input id="name" type="text" wire:model="name" class="{{ $input }} w-full">
                    </x-field>

                    <x-field label="Dueño" for="userId" error="userId"
                             hint="El token no puede hacer más de lo que esta persona puede hacer.">
                        <select id="userId" wire:model="userId" class="{{ $input }} w-full">
                            @foreach ($users as $user)
                                <option value="{{ $user->id }}">{{ $user->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Vence el" for="expiresAt" error="expiresAt"
                             hint="Dejalo vacío solo si la integración es permanente.">
                        <input id="expiresAt" type="date" wire:model="expiresAt" class="{{ $input }} w-full">
                    </x-field>

                    <fieldset class="sm:col-span-2">
                        <legend class="mb-2 text-sm font-medium text-slate-700">Alcances</legend>
                        @error('scopes') <p class="mb-2 text-xs text-red-600">{{ $message }}</p> @enderror

                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($allScopes as $scope => $description)
                                <label class="flex items-start gap-2 rounded-md border border-slate-200 p-2 text-sm">
                                    <input type="checkbox" value="{{ $scope }}" wire:model="scopes"
                                           class="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                                    <span>
                                        <span class="block font-mono text-xs">{{ $scope }}</span>
                                        <span class="block text-xs text-slate-500">{{ $description }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        <p class="mt-2 text-xs text-slate-500">
                            Elegí lo mínimo que la integración necesite. Un token con más permisos de
                            los que usa solo sirve para hacer más daño si se filtra.
                        </p>
                    </fieldset>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancel"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Emitir
                    </button>
                </div>
            </form>
        </x-modal>
    @endif

    {{-- Revocación --}}
    @if ($revoking)
        <x-modal title="Revocar el token" onClose="cancel">
            <div class="space-y-3 p-5 text-sm">
                <p>
                    Cualquier programa que lo esté usando <strong>dejará de funcionar de inmediato</strong>.
                    El token no se borra: queda registrado como revocado, que es lo que sirve si después
                    hay que reconstruir qué pasó.
                </p>
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                <button type="button" wire:click="cancel"
                        class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                    Cancelar
                </button>
                <button type="button" wire:click="revoke"
                        class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                    Revocar
                </button>
            </div>
        </x-modal>
    @endif
</div>
