@php
    $card = 'rounded-xl border border-slate-200 bg-white shadow-sm';
    $dt = 'text-xs font-semibold tracking-wider text-slate-500 uppercase';
@endphp

<div class="space-y-5">
    <x-flash />

    {{-- Encabezado --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('purchases.index') }}" wire:navigate class="text-xs text-slate-500 underline hover:text-slate-900">
                ← Volver a compras
            </a>
            <h2 class="mt-1 flex flex-wrap items-center gap-2 text-xl font-semibold tracking-tight">
                {{ $purchase->number ?? 'Borrador' }}
                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $purchase->status->badgeClasses() }}">
                    {{ $purchase->status->label() }}
                </span>
            </h2>
            <p class="text-sm text-slate-500">
                {{ $purchase->supplier?->name }} · {{ $purchase->date->format('d/m/Y') }}
            </p>
        </div>

        @can('update', $purchase)
            <a href="{{ route('purchases.edit', $purchase->id) }}" wire:navigate
               class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                Editar
            </a>
        @endcan
    </div>

    @if ($purchase->isVoided())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
            <p class="font-semibold">Compra anulada</p>
            <p class="mt-0.5">
                Anulada el {{ $purchase->voided_at?->format('d/m/Y H:i') }}.
                {{ $purchase->void_reason ? 'Motivo: '.$purchase->void_reason : '' }}
                Su partida se revirtió y la mercadería salió del kardex.
            </p>
        </div>
    @endif

    {{-- Datos --}}
    <div class="{{ $card }} p-5">
        <h3 class="mb-3 text-sm font-semibold">Datos del documento</h3>

        <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="{{ $dt }}">Proveedor</dt>
                <dd class="mt-0.5">{{ $purchase->supplier?->name }}</dd>
                @if ($purchase->supplier?->tax_id)
                    <dd class="text-xs text-slate-500">RTN {{ $purchase->supplier->tax_id }}</dd>
                @endif
            </div>
            <div>
                <dt class="{{ $dt }}">Factura del proveedor</dt>
                <dd class="mt-0.5 font-mono text-xs">{{ $purchase->supplier_invoice_number ?: '—' }}</dd>
            </div>
            <div>
                <dt class="{{ $dt }}">Condición</dt>
                <dd class="mt-0.5">{{ $purchase->payment_condition->label() }}</dd>
                @if ($purchase->isOnCredit() && $purchase->due_date)
                    <dd class="text-xs text-slate-500">Vence el {{ $purchase->due_date->format('d/m/Y') }}</dd>
                @endif
            </div>
            <div>
                <dt class="{{ $dt }}">Entró a</dt>
                <dd class="mt-0.5">{{ $purchase->warehouse?->name ?? '—' }}</dd>
                <dd class="text-xs text-slate-500">{{ $purchase->branch?->name }}</dd>
            </div>
        </dl>
    </div>

    {{-- Renglones --}}
    <div class="{{ $card }} overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-3">
            <h3 class="text-sm font-semibold">Qué se compró</h3>
        </div>

        <div class="table-stacked-wrap overflow-x-auto">
            <table class="table-stacked w-full text-sm">
                <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                    <tr role="row">
                        <th role="columnheader" class="px-4 py-2 font-semibold">Concepto</th>
                        <th role="columnheader" class="px-4 py-2 font-semibold">Va a</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Cantidad</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Costo</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Total</th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    @foreach ($purchase->items as $item)
                        <tr role="row">
                            <td role="cell" data-label="Concepto" class="px-4 py-1.5">
                                {{ $item->description ?: $item->product?->name }}
                                @if ($item->product?->code)
                                    <span class="block font-mono text-[10px] text-slate-400">{{ $item->product->code }}</span>
                                @endif
                            </td>
                            {{-- Lo que compra una PYME cae en dos sitios muy
                                 distintos: mercadería que entra al inventario, o
                                 gasto que se va directo a resultados. --}}
                            <td role="cell" data-label="Va a" class="px-4 py-1.5 text-xs text-slate-600">
                                @if ($item->goesToInventory())
                                    Inventario
                                @else
                                    {{ $item->expenseAccount?->name ?? 'Gasto' }}
                                    <span class="block font-mono text-[10px] text-slate-400">{{ $item->expenseAccount?->code }}</span>
                                @endif
                            </td>
                            <td role="cell" data-label="Cantidad" class="px-4 py-1.5 text-right tabular-nums">
                                {{ rtrim(rtrim($item->quantity, '0'), '.') }}
                            </td>
                            <td role="cell" data-label="Costo" class="px-4 py-1.5 text-right tabular-nums">
                                {{ $item->netUnitCost()->format() }}
                            </td>
                            <td role="cell" data-label="Total" class="px-4 py-1.5 text-right font-medium tabular-nums">
                                {{ $item->totalAmount()->format() }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-3">
            <dl class="w-full max-w-xs space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt class="text-slate-500">Subtotal</dt>
                    <dd class="tabular-nums">{{ $purchase->subtotalAmount()->format() }}</dd>
                </div>
                @if (! $purchase->discountAmount()->isZero())
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Descuento</dt>
                        <dd class="tabular-nums">−{{ $purchase->discountAmount()->format() }}</dd>
                    </div>
                @endif
                <div class="flex justify-between">
                    <dt class="text-slate-500">Impuestos</dt>
                    <dd class="tabular-nums">{{ $purchase->taxAmount()->format() }}</dd>
                </div>
                <div class="flex justify-between border-t border-slate-300 pt-1 text-base font-semibold">
                    <dt>Total</dt>
                    <dd class="tabular-nums">L {{ $purchase->totalAmount()->format() }}</dd>
                </div>
            </dl>
        </div>
    </div>

    {{-- Lo que se debe y lo que se ha pagado --}}
    @if ($purchase->payable)
        <div class="{{ $card }} p-5">
            <h3 class="mb-3 text-sm font-semibold">Cuenta por pagar</h3>

            <dl class="grid gap-4 text-sm sm:grid-cols-3">
                <div>
                    <dt class="{{ $dt }}">Original</dt>
                    <dd class="mt-0.5 tabular-nums">{{ $purchase->payable->originalAmount()->format() }}</dd>
                </div>
                <div>
                    <dt class="{{ $dt }}">Pagado</dt>
                    <dd class="mt-0.5 tabular-nums">{{ $purchase->payable->paidAmount()->format() }}</dd>
                </div>
                <div>
                    <dt class="{{ $dt }}">Saldo</dt>
                    <dd class="mt-0.5 font-semibold tabular-nums">{{ $purchase->payable->balanceAmount()->format() }}</dd>
                </div>
            </dl>

            @if ($purchase->payable->applications->isNotEmpty())
                <p class="mt-4 {{ $dt }}">Pagos aplicados</p>
                <ul class="mt-1 divide-y divide-slate-100 text-sm">
                    @foreach ($purchase->payable->applications as $application)
                        <li class="flex items-center justify-between gap-3 py-1.5">
                            <a href="{{ route('payments.show', $application->payment?->id) }}" wire:navigate
                               class="min-w-0 truncate underline hover:text-slate-900">
                                {{ $application->payment?->number ?? 'Pago' }}
                                <span class="text-xs text-slate-500">
                                    {{ $application->payment?->date?->format('d/m/Y') }}
                                </span>
                            </a>
                            <span class="shrink-0 tabular-nums">{{ $application->amountMoney()->format() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- El asiento --}}
    @if ($entry)
        <div class="{{ $card }} flex flex-wrap items-center justify-between gap-3 p-5">
            <div>
                <h3 class="text-sm font-semibold">Partida contable</h3>
                <p class="text-sm text-slate-500">
                    {{ $entry->number }} · {{ $entry->date->format('d/m/Y') }} · {{ $entry->concept }}
                </p>
            </div>
            @can('view', $entry)
                <a href="{{ route('journal.edit', $entry->id) }}" wire:navigate
                   class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium hover:bg-slate-50">
                    Ver la partida
                </a>
            @endcan
        </div>
    @endif
</div>
