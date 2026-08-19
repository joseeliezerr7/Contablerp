@php $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Caja</h2>
            <p class="text-sm text-slate-500">Apertura, cierre y arqueo del efectivo.</p>
        </div>

        @can('create', \App\Domains\Treasury\Models\CashSession::class)
            <button type="button" wire:click="openTill"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                Abrir caja
            </button>
        @endcan
    </div>

    {{-- Sesiones abiertas: lo primero que quiere ver un cajero. --}}
    @if ($openSessions->isNotEmpty())
        <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($openSessions as $session)
                <div class="rounded-xl border border-emerald-200 bg-white p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="font-mono text-xs text-slate-500">{{ $session->number }}</p>
                            <p class="font-semibold">{{ $session->account->name }}</p>
                            <p class="text-xs text-slate-500">
                                {{ $session->branch->name }} · {{ $session->cashier->name }}
                            </p>
                        </div>
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $session->status->badgeClasses() }}">
                            {{ $session->status->label() }}
                        </span>
                    </div>

                    <dl class="mt-3 space-y-1 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Abierta</dt>
                            <dd>{{ $session->opened_at->format('d/m/Y H:i') }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Fondo inicial</dt>
                            <dd class="font-mono">{{ $session->openingFloat()->format() }}</dd>
                        </div>
                        <div class="flex justify-between border-t border-slate-100 pt-1">
                            <dt class="font-medium">Debería haber</dt>
                            <dd class="font-mono font-semibold">{{ $session->getAttribute('expected_now')->format() }}</dd>
                        </div>
                    </dl>

                    @can('close', $session)
                        <button type="button" wire:click="confirmClose({{ $session->id }})"
                                class="mt-3 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50">
                            Cerrar y arquear
                        </button>
                    @endcan
                </div>
            @endforeach
        </div>
    @endif

    <h3 class="mb-2 text-sm font-semibold text-slate-600">Sesiones cerradas</h3>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Sesión</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Caja</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Cajero</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Cerrada</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Esperado</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Contado</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Diferencia</th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($sessions as $session)
                    <tr role="row" class="hover:bg-slate-50">
                        <td role="cell" data-label="Sesión" class="px-4 py-1.5 font-mono text-xs">{{ $session->number }}</td>
                        <td role="cell" data-label="Caja" class="px-4 py-1.5">{{ $session->account->name }}</td>
                        <td role="cell" data-label="Cajero" class="px-4 py-1.5 text-slate-600">{{ $session->cashier->name }}</td>
                        <td role="cell" data-label="Cerrada" class="px-4 py-1.5 whitespace-nowrap">
                            {{ $session->closed_at?->format('d/m/Y H:i') ?? '—' }}
                        </td>
                        <td role="cell" data-label="Esperado" class="px-4 py-1.5 text-right font-mono">
                            {{ $session->expectedAmount()->format() }}
                        </td>
                        <td role="cell" data-label="Contado" class="px-4 py-1.5 text-right font-mono">
                            {{ $session->countedAmount()->format() }}
                        </td>
                        <td role="cell" data-label="Diferencia"
                            class="px-4 py-1.5 text-right font-mono {{ $session->differenceAmount()->isZero() ? 'text-slate-400' : ($session->isShort() ? 'font-semibold text-red-700' : 'font-semibold text-amber-700') }}">
                            {{ $session->differenceAmount()->format() }}
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="7" class="px-4 py-8 text-center text-slate-500">
                            Todavía no hay sesiones cerradas.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $sessions->links() }}</div>

    @if ($showOpen)
        <x-modal title="Abrir caja" onClose="$set('showOpen', false)">
            <form wire:submit="save">
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Sucursal" for="branch_id" error="branch_id">
                        <select id="branch_id" wire:model="branch_id" class="{{ $input }}">
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Caja" for="account_id" error="account_id"
                             hint="Cada caja necesita su propia cuenta contable para poder arquearse por separado.">
                        <select id="account_id" wire:model="account_id" class="{{ $input }}">
                            @foreach ($cashAccounts as $option)
                                <option value="{{ $option->id }}">{{ $option->code }} — {{ $option->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Fondo inicial" for="opening_float" error="opening_float"
                             hint="El efectivo con el que arranca el turno.">
                        <input id="opening_float" type="text" inputmode="decimal" wire:model="opening_float"
                               class="{{ $input }} text-right font-mono">
                    </x-field>

                    <x-field label="Notas" for="notes" error="notes">
                        <input id="notes" type="text" wire:model="notes" class="{{ $input }}">
                    </x-field>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="$set('showOpen', false)"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Abrir
                    </button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($closingId)
        <x-modal title="Arqueo de caja" onClose="cancelClose">
            <form wire:submit="close">
                <div class="space-y-3 p-5">
                    <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        Cuenta el efectivo y anota lo que hay de verdad. Si no coincide con el libro,
                        la diferencia se contabiliza como sobrante o faltante: no se ajusta el conteo
                        para cuadrar.
                    </p>

                    <x-field label="Efectivo contado" for="counted_amount" error="counted_amount">
                        <input id="counted_amount" type="text" inputmode="decimal" wire:model="counted_amount"
                               autofocus class="{{ $input }} text-right font-mono">
                    </x-field>

                    <x-field label="Notas" for="notes" error="notes">
                        <textarea id="notes" wire:model="notes" rows="2" class="{{ $input }}"></textarea>
                    </x-field>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancelClose"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Cerrar caja
                    </button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
