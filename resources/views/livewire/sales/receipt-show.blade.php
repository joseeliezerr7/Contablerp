@php
    use App\Support\Money;

    $card = 'rounded-xl border border-slate-200 bg-white shadow-sm';
    $dt = 'text-xs font-semibold tracking-wider text-slate-500 uppercase';

    $aplicado = Money::sum($receipt->applications->map->amountMoney()->all());
@endphp

<div class="space-y-5">
    <x-flash />

    {{-- Encabezado --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('receipts.index') }}" wire:navigate class="text-xs text-slate-500 underline hover:text-slate-900">
                ← Volver a recibos
            </a>
            <h2 class="mt-1 flex flex-wrap items-center gap-2 text-xl font-semibold tracking-tight">
                {{ $receipt->number }}
                @if ($receipt->isVoided())
                    <span class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">Anulado</span>
                @else
                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Emitido</span>
                @endif
            </h2>
            <p class="text-sm text-slate-500">
                {{ $receipt->customer?->name }} · {{ $receipt->date->format('d/m/Y') }}
            </p>
        </div>

        <p class="text-right">
            <span class="{{ $dt }}">Monto del recibo</span>
            <span class="block text-2xl font-semibold tabular-nums">L {{ $receipt->amountMoney()->format() }}</span>
        </p>
    </div>

    @if ($receipt->isVoided())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
            <p class="font-semibold">Recibo anulado</p>
            <p class="mt-0.5">
                Anulado el {{ $receipt->voided_at?->format('d/m/Y H:i') }}.
                {{ $receipt->void_reason ? 'Motivo: '.$receipt->void_reason : '' }}
                Los saldos de las facturas que había abonado volvieron a subir.
            </p>
        </div>
    @endif

    {{-- Datos --}}
    <div class="{{ $card }} p-5">
        <h3 class="mb-3 text-sm font-semibold">Datos del cobro</h3>

        <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="{{ $dt }}">Cliente</dt>
                <dd class="mt-0.5">{{ $receipt->customer?->name }}</dd>
                @if ($receipt->customer?->tax_id)
                    <dd class="text-xs text-slate-500">RTN {{ $receipt->customer->tax_id }}</dd>
                @endif
            </div>
            <div>
                <dt class="{{ $dt }}">Forma de pago</dt>
                <dd class="mt-0.5">{{ $receipt->payment_method->label() }}</dd>
                @if ($receipt->reference)
                    <dd class="text-xs text-slate-500">Ref. {{ $receipt->reference }}</dd>
                @endif
            </div>
            <div>
                <dt class="{{ $dt }}">Entró a</dt>
                <dd class="mt-0.5">{{ $receipt->depositAccount?->name ?? '—' }}</dd>
                <dd class="font-mono text-[10px] text-slate-400">{{ $receipt->depositAccount?->code }}</dd>
            </div>
            <div>
                <dt class="{{ $dt }}">Sucursal</dt>
                <dd class="mt-0.5">{{ $receipt->branch?->name ?? '—' }}</dd>
            </div>
        </dl>

        @if ($receipt->notes)
            <div class="mt-4 border-t border-slate-100 pt-3">
                <p class="{{ $dt }}">Notas</p>
                <p class="mt-0.5 text-sm">{{ $receipt->notes }}</p>
            </div>
        @endif
    </div>

    {{--
        La pregunta que este documento tiene que responder: contra qué facturas
        se aplicó. Sin esto, un abono de diez mil es un número sin historia.
    --}}
    <div class="{{ $card }} overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-3">
            <h3 class="text-sm font-semibold">Facturas que abonó</h3>
        </div>

        <div class="table-stacked-wrap overflow-x-auto">
            <table class="table-stacked w-full text-sm">
                <thead role="rowgroup" class="border-b border-slate-200 bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                    <tr role="row">
                        <th role="columnheader" class="px-4 py-2 font-semibold">Documento</th>
                        <th role="columnheader" class="px-4 py-2 font-semibold">Vence</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Monto original</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Abonado aquí</th>
                        <th role="columnheader" class="px-4 py-2 text-right font-semibold">Saldo hoy</th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    @forelse ($receipt->applications as $application)
                        @php $receivable = $application->receivable; @endphp
                        <tr role="row">
                            <td role="cell" data-label="Documento" class="px-4 py-1.5 font-mono text-xs">
                                @if ($receivable?->sale)
                                    <a href="{{ route('sales.show', $receivable->sale->id) }}" wire:navigate
                                       class="underline hover:text-slate-900">
                                        {{ $receivable->document_number }}
                                    </a>
                                @else
                                    {{ $receivable?->document_number ?? '—' }}
                                @endif
                            </td>
                            <td role="cell" data-label="Vence" class="px-4 py-1.5 text-xs whitespace-nowrap">
                                {{ $receivable?->due_date?->format('d/m/Y') ?? '—' }}
                            </td>
                            <td role="cell" data-label="Monto original" class="px-4 py-1.5 text-right tabular-nums">
                                {{ $receivable?->originalAmount()->format() ?? '—' }}
                            </td>
                            <td role="cell" data-label="Abonado aquí" class="px-4 py-1.5 text-right font-medium tabular-nums">
                                {{ $application->amountMoney()->format() }}
                            </td>
                            <td role="cell" data-label="Saldo hoy" class="px-4 py-1.5 text-right tabular-nums">
                                {{ $receivable?->balanceAmount()->format() ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        {{-- No debería ocurrir: `ReceiptService::create` rechaza
                             un recibo sin aplicaciones. Queda por si un dato
                             viejo o una migración dejara alguno suelto, para que
                             la pantalla lo diga en vez de mostrarse vacía. --}}
                        <tr role="row">
                            <td role="cell" colspan="5" class="px-4 py-6 text-center text-slate-500">
                                Este recibo no tiene aplicaciones registradas.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-3">
            <dl class="w-full max-w-xs space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt class="text-slate-500">Aplicado a facturas</dt>
                    <dd class="tabular-nums">{{ $aplicado->format() }}</dd>
                </div>
                @if (! $aplicado->equals($receipt->amountMoney()))
                    {{-- No debería pasar: el monto del recibo se calcula como la
                         suma de sus aplicaciones. Si difiere, algo movió una
                         aplicación sin recalcular el encabezado, y eso hay que
                         verlo, no esconderlo. --}}
                    <div class="flex justify-between text-red-700">
                        <dt class="font-medium">Descuadre</dt>
                        <dd class="tabular-nums">{{ $receipt->amountMoney()->minus($aplicado)->format() }}</dd>
                    </div>
                @endif
                <div class="flex justify-between border-t border-slate-300 pt-1 text-base font-semibold">
                    <dt>Total del recibo</dt>
                    <dd class="tabular-nums">L {{ $receipt->amountMoney()->format() }}</dd>
                </div>
            </dl>
        </div>
    </div>

    {{-- El asiento que generó --}}
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
