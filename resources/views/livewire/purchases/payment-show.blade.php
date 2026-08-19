@php
    use App\Support\Money;

    $card = 'rounded-xl border border-slate-200 bg-white shadow-sm';
    $dt = 'text-xs font-semibold tracking-wider text-slate-500 uppercase';

    $aplicado = Money::sum($payment->applications->map->amountMoney()->all());
    $retenido = Money::sum($withholdings->map->amountMoney()->all());
@endphp

<div class="space-y-5">
    <x-flash />

    {{-- Encabezado --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('payments.index') }}" wire:navigate class="text-xs text-slate-500 underline hover:text-slate-900">
                ← Volver a pagos
            </a>
            <h2 class="mt-1 flex flex-wrap items-center gap-2 text-xl font-semibold tracking-tight">
                {{ $payment->number }}
                @if ($payment->isVoided())
                    <span class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">Anulado</span>
                @else
                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Emitido</span>
                @endif
            </h2>
            <p class="text-sm text-slate-500">
                {{ $payment->supplier?->name }} · {{ $payment->date->format('d/m/Y') }}
            </p>
        </div>

        <p class="text-right">
            <span class="{{ $dt }}">Salió de caja</span>
            <span class="block text-2xl font-semibold tabular-nums">L {{ $payment->amountMoney()->format() }}</span>
        </p>
    </div>

    @if ($payment->isVoided())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
            <p class="font-semibold">Pago anulado</p>
            <p class="mt-0.5">
                Anulado el {{ $payment->voided_at?->format('d/m/Y H:i') }}.
                {{ $payment->void_reason ? 'Motivo: '.$payment->void_reason : '' }}
                Los saldos de las facturas que había cancelado volvieron a subir.
            </p>
        </div>
    @endif

    {{-- Datos --}}
    <div class="{{ $card }} p-5">
        <h3 class="mb-3 text-sm font-semibold">Datos del pago</h3>

        <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="{{ $dt }}">Proveedor</dt>
                <dd class="mt-0.5">{{ $payment->supplier?->name }}</dd>
                @if ($payment->supplier?->tax_id)
                    <dd class="text-xs text-slate-500">RTN {{ $payment->supplier->tax_id }}</dd>
                @endif
            </div>
            <div>
                <dt class="{{ $dt }}">Forma de pago</dt>
                <dd class="mt-0.5">{{ $payment->payment_method->label() }}</dd>
                @if ($payment->reference)
                    <dd class="text-xs text-slate-500">Ref. {{ $payment->reference }}</dd>
                @endif
            </div>
            <div>
                <dt class="{{ $dt }}">Salió de</dt>
                <dd class="mt-0.5">{{ $payment->paymentAccount?->name ?? '—' }}</dd>
                <dd class="font-mono text-[10px] text-slate-400">{{ $payment->paymentAccount?->code }}</dd>
            </div>
            <div>
                <dt class="{{ $dt }}">Sucursal</dt>
                <dd class="mt-0.5">{{ $payment->branch?->name ?? '—' }}</dd>
            </div>
        </dl>

        @if ($payment->notes)
            <div class="mt-4 border-t border-slate-100 pt-3">
                <p class="{{ $dt }}">Notas</p>
                <p class="mt-0.5 text-sm">{{ $payment->notes }}</p>
            </div>
        @endif
    </div>

    {{-- Contra qué facturas se aplicó --}}
    <div class="{{ $card }} overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-3">
            <h3 class="text-sm font-semibold">Facturas que canceló</h3>
        </div>

        <div class="table-stacked-wrap overflow-x-auto">
            <table class="table-stacked w-full text-sm">
                <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                    <tr role="row">
                        <th role="columnheader" class="px-4 py-2 font-semibold">Documento</th>
                        <th role="columnheader" class="px-4 py-2 font-semibold">Vence</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Monto original</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Pagado aquí</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Saldo hoy</th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    @forelse ($payment->applications as $application)
                        @php $payable = $application->payable; @endphp
                        <tr role="row">
                            <td role="cell" data-label="Documento" class="px-4 py-1.5 font-mono text-xs">
                                @if ($payable?->purchase)
                                    <a href="{{ route('purchases.show', $payable->purchase->id) }}" wire:navigate
                                       class="underline hover:text-slate-900">{{ $payable->document_number }}</a>
                                @else
                                    {{ $payable?->document_number ?? '—' }}
                                @endif
                            </td>
                            <td role="cell" data-label="Vence" class="px-4 py-1.5 text-xs whitespace-nowrap">
                                {{ $payable?->due_date?->format('d/m/Y') ?? '—' }}
                            </td>
                            <td role="cell" data-label="Monto original" class="px-4 py-1.5 text-right tabular-nums">
                                {{ $payable?->originalAmount()->format() ?? '—' }}
                            </td>
                            <td role="cell" data-label="Pagado aquí" class="px-4 py-1.5 text-right font-medium tabular-nums">
                                {{ $application->amountMoney()->format() }}
                            </td>
                            <td role="cell" data-label="Saldo hoy" class="px-4 py-1.5 text-right tabular-nums">
                                {{ $payable?->balanceAmount()->format() ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr role="row">
                            <td role="cell" colspan="5" class="px-4 py-6 text-center text-slate-500">
                                Este pago no tiene aplicaciones registradas.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-3">
            <dl class="w-full max-w-xs space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt class="text-slate-500">Se le abonó al proveedor</dt>
                    <dd class="tabular-nums">{{ $aplicado->format() }}</dd>
                </div>
                @if (! $retenido->isZero())
                    {{-- Lo retenido baja la deuda igual que el efectivo, pero no
                         sale de la cuenta: queda a deber al fisco. --}}
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Menos lo retenido</dt>
                        <dd class="tabular-nums">−{{ $retenido->format() }}</dd>
                    </div>
                @endif
                <div class="flex justify-between border-t border-slate-300 pt-1 text-base font-semibold">
                    <dt>Salió de caja</dt>
                    <dd class="tabular-nums">L {{ $payment->amountMoney()->format() }}</dd>
                </div>
            </dl>
        </div>
    </div>

    {{-- Retenciones practicadas --}}
    @if ($withholdings->isNotEmpty())
        <div class="{{ $card }} p-5">
            <h3 class="mb-1 text-sm font-semibold">Lo que se le retuvo</h3>
            <p class="mb-3 text-xs text-slate-500">
                No se le pagó al proveedor: queda a deber al fisco hasta enterarlo.
            </p>

            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($withholdings as $withholding)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <span class="min-w-0">
                            <span class="block">{{ $withholding->type?->name ?? 'Retención' }}</span>
                            <span class="block text-xs text-slate-500">
                                {{ rtrim(rtrim($withholding->rate, '0'), '.') }}% sobre
                                {{ $withholding->baseAmount()->format() }}
                                @if ($withholding->certificate_number)
                                    · constancia {{ $withholding->certificate_number }}
                                @endif
                            </span>
                        </span>
                        <span class="shrink-0 font-medium tabular-nums">{{ $withholding->amountMoney()->format() }}</span>
                    </li>
                @endforeach
            </ul>
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
