@php
    $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none';
    $pending = $this->openReceivables();
    $applied = $this->appliedTotal();
@endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Recibos de cobro</h2>
            <p class="text-sm text-slate-500">Un recibo puede cancelar varias facturas del mismo cliente.</p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Número, referencia o cliente…"
                   class="{{ $input }} w-64">
            @can('create', \App\Domains\Receivables\Models\Receipt::class)
                <button type="button" wire:click="create"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Nuevo recibo
                </button>
            @endcan
        </div>
    </div>

    <div class="table-stacked-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="table-stacked w-full text-sm">
            <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                <tr role="row">
                    <th role="columnheader" class="px-4 py-2 font-semibold">Número</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Fecha</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Cliente</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Forma de pago</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Importe</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Acciones</th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($receipts as $receipt)
                    <tr role="row" class="hover:bg-slate-50 {{ $receipt->isVoided() ? 'opacity-60' : '' }}">
                        <td role="cell" data-label="Número" class="px-4 py-1.5 font-mono text-xs">{{ $receipt->number }}</td>
                        <td role="cell" data-label="Fecha" class="px-4 py-1.5 whitespace-nowrap">{{ $receipt->date->format('d/m/Y') }}</td>
                        <td role="cell" data-label="Cliente" class="px-4 py-1.5">{{ $receipt->customer->name }}</td>
                        <td role="cell" data-label="Forma de pago" class="px-4 py-1.5 text-slate-600">
                            {{ $receipt->payment_method->label() }}
                            @if ($receipt->reference)
                                <span class="text-xs text-slate-500">· {{ $receipt->reference }}</span>
                            @endif
                        </td>
                        <td role="cell" data-label="Importe" class="px-4 py-1.5 text-right font-mono">{{ $receipt->amountMoney()->format() }}</td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            @if ($receipt->isVoided())
                                <span class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">Anulado</span>
                            @else
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Emitido</span>
                            @endif
                        </td>
                        <td role="cell" data-label="Acciones" class="px-4 py-1.5">
                            <div class="flex flex-wrap justify-end gap-x-3 gap-y-1 text-xs">
                                <a href="{{ route('receipts.show', $receipt->id) }}" wire:navigate
                                   class="font-medium text-slate-700 underline hover:text-slate-900">Ver</a>
                                @can('void', $receipt)
                                    <button type="button" wire:click="confirmVoid({{ $receipt->id }})"
                                            class="text-red-600 underline hover:text-red-800">Anular</button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr role="row"><td role="cell" colspan="7" class="px-4 py-8 text-center text-slate-500">Sin recibos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $receipts->links() }}</div>

    @if ($showForm)
        <x-modal title="Nuevo recibo de cobro">
            <form wire:submit="save">
                <div class="grid gap-4 p-5 sm:grid-cols-3">
                    <x-field label="Cliente" for="customer_id" error="customer_id" class="sm:col-span-2">
                        <select id="customer_id" wire:model.live="customer_id" class="{{ $input }}">
                            <option value="">Selecciona…</option>
                            @foreach ($customers as $option)
                                <option value="{{ $option->id }}">{{ $option->code }} — {{ $option->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Fecha" for="date" error="date">
                        <input id="date" type="date" wire:model="date" class="{{ $input }}">
                    </x-field>

                    <x-field label="Forma de pago" for="payment_method" error="payment_method">
                        <select id="payment_method" wire:model.live="payment_method" class="{{ $input }}">
                            @foreach ($methods as $method)
                                <option value="{{ $method->value }}">{{ $method->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Referencia" for="reference" error="reference"
                             :hint="in_array($payment_method, ['check','transfer'], true) ? 'Número de cheque o transferencia.' : null">
                        <input id="reference" type="text" wire:model="reference" class="{{ $input }}">
                    </x-field>

                    <x-field label="Ingresa a" for="deposit_account_id" error="deposit_account_id">
                        <select id="deposit_account_id" wire:model="deposit_account_id" class="{{ $input }}">
                            @foreach ($cashAccounts as $accountOption)
                                <option value="{{ $accountOption->id }}">{{ $accountOption->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>

                @error('applications')
                    <div class="mx-5 mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                        {{ $message }}
                    </div>
                @enderror

                <div class="border-t border-slate-200 px-5 py-3">
                    <p class="mb-2 text-sm font-medium text-slate-700">Documentos pendientes</p>

                    @if ($customer_id === null)
                        <p class="py-4 text-center text-sm text-slate-500">Selecciona un cliente.</p>
                    @elseif ($pending->isEmpty())
                        <p class="py-4 text-center text-sm text-slate-500">Este cliente no tiene saldos pendientes.</p>
                    @else
                        <table class="table-stacked w-full text-sm">
                            <thead role="rowgroup" class="text-left text-xs tracking-wider text-slate-500 uppercase">
                                <tr role="row">
                                    <th role="columnheader" class="py-1 font-semibold">Documento</th>
                                    <th role="columnheader" class="py-1 font-semibold">Vence</th>
                                    <th role="columnheader" class="py-1 text-right font-semibold">Saldo</th>
                                    <th role="columnheader" class="w-32 py-1 text-right font-semibold">A aplicar</th>
                                </tr>
                            </thead>
                            <tbody role="rowgroup" class="divide-y divide-slate-100">
                                @foreach ($pending as $receivable)
                                    <tr role="row" wire:key="rec-{{ $receivable->id }}">
                                        <td role="cell" data-label="Documento" class="py-1 font-mono text-xs">{{ $receivable->document_number }}</td>
                                        <td role="cell" data-label="Vence" class="py-1 {{ $receivable->isOverdue() ? 'text-red-700' : 'text-slate-600' }}">
                                            {{ $receivable->due_date->format('d/m/Y') }}
                                            @if ($receivable->isOverdue())
                                                <span class="text-xs">({{ $receivable->daysOverdue() }} d)</span>
                                            @endif
                                        </td>
                                        <td role="cell" data-label="Saldo" class="py-1 text-right font-mono">{{ $receivable->balanceAmount()->format() }}</td>
                                        <td role="cell" data-label="A aplicar" class="py-1">
                                            <input type="text" inputmode="decimal"
                                                   wire:model.live.debounce.400ms="applications.{{ $receivable->id }}"
                                                   class="w-full rounded border border-slate-200 px-2 py-1 text-right font-mono text-sm"
                                                   placeholder="0.00">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot role="rowgroup" class="border-t-2 border-slate-300 font-semibold">
                                <tr role="row">
                                    <td role="cell" colspan="3" class="py-2 text-right">Total del recibo</td>
                                    <td role="cell" data-label="Total" class="py-2 text-right font-mono">{{ $applied->format() }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    @endif
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancel"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Cancelar
                    </button>
                    <button type="submit" @disabled($applied->isZero())
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300">
                        Registrar cobro
                    </button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($voidingId)
        <x-modal title="Anular recibo" onClose="cancelVoid">
            <form wire:submit="void">
                <div class="space-y-3 p-5">
                    <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        Se devolverá el saldo a las facturas que este recibo había cancelado y se
                        revertirá su partida contable.
                    </p>

                    <x-field label="Motivo" for="voidReason" error="voidReason">
                        <textarea id="voidReason" wire:model="voidReason" rows="3"
                                  class="{{ $input }}"></textarea>
                    </x-field>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancelVoid"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Anular
                    </button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
