@php
    $card = 'rounded-xl border border-slate-200 bg-white shadow-sm';
    $dt = 'text-xs font-semibold tracking-wider text-slate-500 uppercase';
@endphp

<div class="space-y-5">
    <x-flash />

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('treasury.checks.index') }}" wire:navigate class="text-xs text-slate-500 underline hover:text-slate-900">
                ← Volver a cheques
            </a>
            <h2 class="mt-1 flex flex-wrap items-center gap-2 text-xl font-semibold tracking-tight">
                Cheque {{ $check->number }}
                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $check->status->badgeClasses() }}">
                    {{ $check->status->label() }}
                </span>
            </h2>
            <p class="text-sm text-slate-500">
                A nombre de {{ $check->payee }} · girado el {{ $check->date->format('d/m/Y') }}
            </p>
        </div>

        <p class="text-right">
            <span class="{{ $dt }}">Monto</span>
            <span class="block text-2xl font-semibold tabular-nums">L {{ $check->amountMoney()->format() }}</span>
        </p>
    </div>

    @if ($check->isVoided())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
            <p class="font-semibold">Cheque anulado</p>
            <p class="mt-0.5">
                {{ $check->void_reason ? 'Motivo: '.$check->void_reason.' ' : '' }}
                No debe pagarse: si el físico anda en la calle, avisale al banco.
            </p>
        </div>
    @endif

    {{-- El recorrido del cheque --}}
    <div class="{{ $card }} p-5">
        <h3 class="mb-3 text-sm font-semibold">Recorrido</h3>

        {{--
            Un cheque vive en tres momentos: se gira, se entrega, el banco lo
            paga. Hasta que el banco no lo paga, la plata sigue en la cuenta
            aunque contablemente ya salió — es justo lo que concilia la
            conciliación bancaria.
        --}}
        <ol class="grid gap-4 text-sm sm:grid-cols-3">
            <li class="rounded-lg border border-slate-200 p-3">
                <p class="{{ $dt }}">Girado</p>
                <p class="mt-1 font-medium">{{ $check->date->format('d/m/Y') }}</p>
                <p class="text-xs text-slate-500">Se registró y salió del saldo en libros.</p>
            </li>
            <li class="rounded-lg border p-3 {{ $check->delivered_on ? 'border-slate-200' : 'border-dashed border-slate-300 opacity-60' }}">
                <p class="{{ $dt }}">Entregado</p>
                <p class="mt-1 font-medium">{{ $check->delivered_on?->format('d/m/Y') ?? 'Todavía no' }}</p>
                <p class="text-xs text-slate-500">El beneficiario ya lo tiene en la mano.</p>
            </li>
            <li class="rounded-lg border p-3 {{ $check->cleared_on ? 'border-slate-200' : 'border-dashed border-slate-300 opacity-60' }}">
                <p class="{{ $dt }}">Cobrado</p>
                <p class="mt-1 font-medium">{{ $check->cleared_on?->format('d/m/Y') ?? 'Todavía no' }}</p>
                <p class="text-xs text-slate-500">El banco lo pagó; ya concilia contra el estado de cuenta.</p>
            </li>
        </ol>
    </div>

    {{-- Datos --}}
    <div class="grid gap-4 lg:grid-cols-2">
        <div class="{{ $card }} p-5">
            <h3 class="mb-3 text-sm font-semibold">Datos del cheque</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Cuenta bancaria</dt>
                    <dd class="text-right">{{ $check->bankAccount?->label() ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Beneficiario</dt>
                    <dd class="text-right">{{ $check->payee }}</dd>
                </div>
                @if ($check->notes)
                    <div class="border-t border-slate-100 pt-2">
                        <dt class="{{ $dt }}">Notas</dt>
                        <dd class="mt-0.5">{{ $check->notes }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- De dónde nació --}}
        <div class="{{ $card }} p-5">
            <h3 class="mb-3 text-sm font-semibold">De dónde salió</h3>

            @if ($payment)
                <p class="text-sm">
                    Este cheque paga el documento
                    <a href="{{ route('payments.show', $payment->id) }}" wire:navigate
                       class="font-mono text-xs underline hover:text-slate-900">{{ $payment->number }}</a>
                    a {{ $payment->supplier?->name }}.
                </p>
                <p class="mt-2 text-xs text-slate-500">
                    Ahí está el detalle: contra qué facturas se aplicó y qué se le retuvo.
                </p>
            @else
                <p class="text-sm text-slate-500">
                    Se giró suelto, sin un pago a proveedor asociado — un anticipo o un
                    gasto registrado por otra vía.
                </p>
            @endif
        </div>
    </div>
</div>
