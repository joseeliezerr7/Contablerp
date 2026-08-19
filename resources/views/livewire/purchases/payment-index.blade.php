@php
    $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900 focus:outline-none';
    $pending = $this->openPayables();
    $applied = $this->appliedTotal();
@endphp

<div>
    <x-flash />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Pagos a proveedores</h2>
            <p class="text-sm text-slate-500">Un pago puede cancelar varias facturas del mismo proveedor.</p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Número, referencia o proveedor…"
                   class="{{ $input }} w-64">
            @can('create', \App\Domains\Payables\Models\Payment::class)
                <button type="button" wire:click="create"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Nuevo pago
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
                    <th role="columnheader" class="px-4 py-2 font-semibold">Proveedor</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Forma de pago</th>
                    <th role="columnheader" class="px-4 py-2 text-right font-semibold">Importe</th>
                    <th role="columnheader" class="px-4 py-2 font-semibold">Estado</th>
                    <th role="columnheader" class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="divide-y divide-slate-100">
                @forelse ($payments as $payment)
                    <tr role="row" class="hover:bg-slate-50 {{ $payment->isVoided() ? 'opacity-60' : '' }}">
                        <td role="cell" data-label="Número" class="px-4 py-1.5 font-mono text-xs">{{ $payment->number }}</td>
                        <td role="cell" data-label="Fecha" class="px-4 py-1.5 whitespace-nowrap">{{ $payment->date->format('d/m/Y') }}</td>
                        <td role="cell" data-label="Proveedor" class="px-4 py-1.5">{{ $payment->supplier->name }}</td>
                        <td role="cell" data-label="Forma de pago" class="px-4 py-1.5 text-slate-600">
                            {{ $payment->payment_method->label() }}
                            @if ($payment->reference)
                                <span class="text-xs text-slate-500">· {{ $payment->reference }}</span>
                            @endif
                        </td>
                        <td role="cell" data-label="Importe" class="px-4 py-1.5 text-right font-mono">{{ $payment->amountMoney()->format() }}</td>
                        <td role="cell" data-label="Estado" class="px-4 py-1.5">
                            @if ($payment->isVoided())
                                <span class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">Anulado</span>
                            @else
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Emitido</span>
                            @endif
                        </td>
                        <td role="cell" data-label="Acciones" class="px-4 py-1.5">
                            <div class="flex flex-wrap justify-end gap-x-3 gap-y-1 text-xs">
                                <a href="{{ route('payments.show', $payment->id) }}" wire:navigate
                                   class="font-medium text-slate-700 underline hover:text-slate-900">Ver</a>
                                @can('void', $payment)
                                    <button type="button" wire:click="confirmVoid({{ $payment->id }})"
                                            class="text-red-600 underline hover:text-red-800">Anular</button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr role="row"><td role="cell" colspan="7" class="px-4 py-8 text-center text-slate-500">Sin pagos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $payments->links() }}</div>

    @if ($showForm)
        <x-modal title="Nuevo pago a proveedor">
            <form wire:submit="save">
                <div class="grid gap-4 p-5 sm:grid-cols-3">
                    <x-field label="Proveedor" for="supplier_id" error="supplier_id" class="sm:col-span-2">
                        <select id="supplier_id" wire:model.live="supplier_id" class="{{ $input }}">
                            <option value="">Selecciona…</option>
                            @foreach ($suppliers as $option)
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

                    <x-field label="Sale de" for="payment_account_id" error="payment_account_id">
                        <select id="payment_account_id" wire:model="payment_account_id" class="{{ $input }}">
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
                    <p class="mb-2 text-sm font-medium text-slate-700">Facturas pendientes</p>

                    @if ($supplier_id === null)
                        <p class="py-4 text-center text-sm text-slate-500">Selecciona un proveedor.</p>
                    @elseif ($pending->isEmpty())
                        <p class="py-4 text-center text-sm text-slate-500">Este proveedor no tiene saldos pendientes.</p>
                    @else
                        <table class="table-stacked w-full text-sm">
                            <thead role="rowgroup" class="text-left text-xs tracking-wider text-slate-500 uppercase">
                                <tr role="row">
                                    <th role="columnheader" class="py-1 font-semibold">Factura</th>
                                    <th role="columnheader" class="py-1 font-semibold">Vence</th>
                                    <th role="columnheader" class="py-1 text-right font-semibold">Saldo</th>
                                    <th role="columnheader" class="w-32 py-1 text-right font-semibold">A pagar</th>
                                </tr>
                            </thead>
                            <tbody role="rowgroup" class="divide-y divide-slate-100">
                                @foreach ($pending as $payable)
                                    <tr role="row" wire:key="pay-{{ $payable->id }}">
                                        <td role="cell" data-label="Factura" class="py-1 font-mono text-xs">{{ $payable->document_number }}</td>
                                        <td role="cell" data-label="Vence" class="py-1 {{ $payable->isOverdue() ? 'text-red-700' : 'text-slate-600' }}">
                                            {{ $payable->due_date->format('d/m/Y') }}
                                            @if ($payable->isOverdue())
                                                <span class="text-xs">({{ $payable->daysOverdue() }} d)</span>
                                            @endif
                                        </td>
                                        <td role="cell" data-label="Saldo" class="py-1 text-right font-mono">{{ $payable->balanceAmount()->format() }}</td>
                                        <td role="cell" data-label="A pagar" class="py-1">
                                            <input type="text" inputmode="decimal"
                                                   wire:model.live.debounce.400ms="applications.{{ $payable->id }}"
                                                   class="w-full rounded border border-slate-200 px-2 py-1 text-right font-mono text-sm"
                                                   placeholder="0.00">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot role="rowgroup" class="border-t-2 border-slate-300 font-semibold">
                                <tr role="row">
                                    <td role="cell" colspan="3" class="py-2 text-right">Total del pago</td>
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
                        Registrar pago
                    </button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($voidingId)
        <x-modal title="Anular pago" onClose="cancelVoid">
            <form wire:submit="void">
                <div class="space-y-3 p-5">
                    <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        Se devolverá el saldo a las facturas que este pago había cancelado y se
                        revertirá su partida contable.
                    </p>

                    <x-field label="Motivo" for="voidReason" error="voidReason">
                        <textarea id="voidReason" wire:model="voidReason" rows="3" class="{{ $input }}"></textarea>
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
