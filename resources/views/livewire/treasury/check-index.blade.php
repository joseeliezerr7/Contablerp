@php $input = 'rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none'; @endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Cheques</h2>
            <p class="text-sm text-slate-500">
                Pendientes de cobro: <span class="font-mono font-semibold">{{ $outstandingTotal->format() }}</span>
            </p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Número o beneficiario…"
                   class="{{ $input }} w-56">
            <select wire:model.live="bankAccountId" class="{{ $input }}">
                <option value="">Todas las cuentas</option>
                @foreach ($bankAccounts as $option)
                    <option value="{{ $option->id }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="statusFilter" class="{{ $input }}">
                <option value="">Todos los estados</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Número</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Fecha</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Cuenta</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Beneficiario</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Importe</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Cobrado</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($checks as $check)
                    <tr role="row" class="hover:bg-slate-50 {{ $check->isVoided() ? 'opacity-60' : '' }}">
                        <td role="cell" data-label="Número" class="px-4 py-1.5 font-mono text-xs">{{ $check->number }}</td>
                        <td role="cell" data-label="Fecha" class="px-4 py-1.5 whitespace-nowrap">{{ $check->date->format('d/m/Y') }}</td>
                        <td role="cell" data-label="Cuenta" class="px-4 py-1.5 text-slate-600">{{ $check->bankAccount->label() }}</td>
                        <td role="cell" data-label="Beneficiario" class="px-4 py-1.5">{{ $check->payee }}</td>
                        <td role="cell" data-label="Importe" class="px-4 py-1.5 text-right font-mono">{{ $check->amountMoney()->format() }}</td>
                        <td role="cell" data-label="Cobrado" class="px-4 py-1.5 whitespace-nowrap text-slate-600">
                            {{ $check->cleared_on?->format('d/m/Y') ?? '—' }}
                        </td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $check->status->badgeClasses() }}">
                                {{ $check->status->label() }}
                            </span>
                        </td>
                        <td role="cell" data-label="Acciones" class="px-4 py-1.5 text-right whitespace-nowrap">
                            <a href="{{ route('treasury.checks.show', $check->id) }}" wire:navigate
                               class="text-xs font-medium text-slate-700 underline hover:text-slate-900">Ver</a>
                            @can('update', $check)
                                @if ($check->status === \App\Domains\Treasury\Enums\CheckStatus::Issued)
                                    <button type="button" wire:click="markDelivered({{ $check->id }})"
                                            class="ml-2 text-xs text-slate-600 underline hover:text-slate-900">Entregar</button>
                                @endif
                                @if ($check->isOutstanding())
                                    <button type="button" wire:click="confirmClear({{ $check->id }})"
                                            class="ml-2 text-xs text-emerald-700 underline hover:text-emerald-900">Cobrado</button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr role="row">
                        <td role="cell" colspan="8" class="px-4 py-8 text-center text-slate-500">
                            No hay cheques que coincidan con el filtro.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $checks->links() }}</div>

    @if ($clearingId)
        <x-modal title="Cheque cobrado" onClose="cancelClear">
            <form wire:submit="markCleared">
                <div class="space-y-3 p-5">
                    <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        Marcar el cheque como cobrado no mueve la contabilidad: el dinero salió del libro
                        cuando se registró el pago. Lo que cambia es la conciliación, donde deja de figurar
                        como cheque pendiente.
                    </p>

                    <x-field label="Fecha en que el banco lo pagó" for="clearedOn" error="clearedOn">
                        <input id="clearedOn" type="date" wire:model="clearedOn"
                               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none">
                    </x-field>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancelClear"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Confirmar
                    </button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
