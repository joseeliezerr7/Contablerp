@php
    use App\Support\AmountInWords;

    $isCreditNote = $kind === 'credit_note';
@endphp

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $document->number }}</title>
    <style>
        @page { margin: 18mm 14mm; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9.5px;
            color: #0f172a;
            margin: 0;
        }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .mono { font-family: DejaVu Sans Mono, monospace; }

        .issuer-name { font-size: 15px; font-weight: bold; }
        .doc-type { font-size: 13px; font-weight: bold; letter-spacing: 1px; }
        .doc-number { font-size: 14px; font-weight: bold; }

        .box {
            border: 1px solid #cbd5e1;
            padding: 6px 8px;
        }

        .head td { vertical-align: top; }

        .party { padding-top: 10px; }
        .party td { padding: 1.5px 0; vertical-align: top; }
        .party .label { width: 92px; color: #64748b; }

        .lines { margin-top: 10px; }
        .lines th {
            background: #e2e8f0;
            border-top: 1px solid #94a3b8;
            border-bottom: 1px solid #94a3b8;
            padding: 4px 5px;
            font-size: 8.5px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .lines td {
            padding: 3.5px 5px;
            border-bottom: 1px solid #f1f5f9;
        }

        .totals td { padding: 2.5px 5px; }
        .totals .grand td {
            border-top: 1px solid #94a3b8;
            font-size: 12px;
            font-weight: bold;
            padding-top: 5px;
        }

        .legend {
            margin-top: 14px;
            border-top: 1px dashed #94a3b8;
            padding-top: 8px;
            font-size: 8.5px;
        }
        .slogan {
            margin-top: 10px;
            text-align: center;
            font-weight: bold;
            font-size: 10px;
            letter-spacing: 0.5px;
        }
        .voided {
            border: 2px solid #dc2626;
            color: #dc2626;
            font-size: 16px;
            font-weight: bold;
            text-align: center;
            padding: 5px;
            margin-bottom: 10px;
            letter-spacing: 3px;
        }
    </style>
</head>
<body>

@if ($document->isVoided())
    <div class="voided">DOCUMENTO ANULADO</div>
@endif

{{-- Emisor y datos del documento --}}
<table class="head">
    <tr>
        <td style="width: 58%">
            <div class="issuer-name">{{ $company->legal_name }}</div>
            @if ($company->trade_name)
                <div class="muted">{{ $company->trade_name }}</div>
            @endif
            <div class="mono">RTN {{ $company->tax_id }}</div>
            @if ($branch->address ?? $company->address)
                <div class="muted">{{ $branch->address ?? $company->address }}</div>
            @endif
            <div class="muted">
                @if ($company->phone) Tel. {{ $company->phone }} @endif
                @if ($company->email) · {{ $company->email }} @endif
            </div>
            <div class="muted">{{ $branch->name }}</div>
        </td>
        <td style="width: 42%">
            <div class="box center">
                <div class="doc-type">{{ mb_strtoupper($title) }}</div>
                <div class="doc-number mono">{{ $document->number }}</div>
                <div style="margin-top: 5px; text-align: left; font-size: 8.5px;">
                    {{-- El CAI, el rango y la fecha límite salen de lo congelado
                         en el documento, no de la autorización vigente: una
                         reimpresión tiene que dar el mismo papel. --}}
                    <div><span class="muted">CAI:</span> <span class="mono">{{ $document->cai }}</span></div>
                    <div><span class="muted">Rango autorizado:</span></div>
                    <div class="mono">{{ $rangeLabel }}</div>
                    <div>
                        <span class="muted">Fecha límite de emisión:</span>
                        {{ $document->fiscal_limit_date?->format('d/m/Y') }}
                    </div>
                </div>
            </div>
        </td>
    </tr>
</table>

{{-- Cliente --}}
<table class="party">
    <tr>
        <td class="label">Cliente</td>
        <td class="bold">{{ $customer->name }}</td>
        <td class="label">Fecha de emisión</td>
        <td>{{ $document->date->format('d/m/Y') }}</td>
    </tr>
    <tr>
        <td class="label">RTN</td>
        <td class="mono">{{ $customer->tax_id ?: 'Consumidor final' }}</td>
        <td class="label">
            {{ $isCreditNote ? 'Factura que acredita' : 'Condición' }}
        </td>
        <td>
            @if ($isCreditNote)
                <span class="mono">{{ $document->sale->number }}</span>
            @else
                {{ $document->payment_condition->label() }}
                @if ($document->isOnCredit())
                    · vence {{ $document->due_date?->format('d/m/Y') }}
                @endif
            @endif
        </td>
    </tr>
    <tr>
        <td class="label">Dirección</td>
        <td colspan="3">{{ $customer->address ?: '—' }}</td>
    </tr>
    @if ($isCreditNote)
        <tr>
            <td class="label">Motivo</td>
            <td colspan="3">{{ $document->reason->label() }} — {{ $document->description }}</td>
        </tr>
    @endif
</table>

{{-- Detalle --}}
<table class="lines">
    <thead>
        <tr>
            <th style="width: 30px" class="center">#</th>
            <th style="width: 52px" class="right">Cant.</th>
            <th>Descripción</th>
            <th style="width: 68px" class="right">Precio</th>
            <th style="width: 62px" class="right">Descuento</th>
            <th style="width: 44px" class="right">ISV %</th>
            <th style="width: 78px" class="right">Importe</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($document->items as $item)
            <tr>
                <td class="center muted">{{ $item->line_number }}</td>
                <td class="right mono">{{ rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ','), '0'), '.') }}</td>
                <td>{{ $item->description }}</td>
                <td class="right mono">{{ number_format((float) $item->unit_price, 2, '.', ',') }}</td>
                <td class="right mono">{{ $item->discountAmount()->format() }}</td>
                <td class="right mono">{{ rtrim(rtrim(number_format((float) $item->tax_rate, 2, '.', ','), '0'), '.') }}</td>
                <td class="right mono">{{ $item->subtotalAmount()->format() }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

{{-- Total en letras y totales --}}
<table style="margin-top: 12px">
    <tr>
        <td style="width: 58%; vertical-align: top; padding-right: 10px">
            <div class="muted" style="font-size: 8.5px">Son</div>
            <div class="bold">{{ AmountInWords::of($document->totalAmount(), $document->currency_code) }}</div>

            @if ($document->notes ?? null)
                <div class="muted" style="margin-top: 8px; font-size: 8.5px">
                    {{ $document->notes }}
                </div>
            @endif
        </td>
        <td style="width: 42%; vertical-align: top">
            <table class="totals">
                <tr>
                    <td class="muted">Importe gravado</td>
                    <td class="right mono">{{ $taxableTotal->format() }}</td>
                </tr>
                @if ($exemptTotal->isPositive())
                    <tr>
                        <td class="muted">Importe exento</td>
                        <td class="right mono">{{ $exemptTotal->format() }}</td>
                    </tr>
                @endif
                @if ($document->discountAmount()->isPositive())
                    <tr>
                        <td class="muted">Descuentos y rebajas</td>
                        <td class="right mono">{{ $document->discountAmount()->format() }}</td>
                    </tr>
                @endif
                @foreach ($taxBreakdown as $row)
                    <tr>
                        <td class="muted">{{ $row['label'] }}</td>
                        <td class="right mono">{{ $row['amount']->format() }}</td>
                    </tr>
                @endforeach
                <tr class="grand">
                    <td>TOTAL A {{ $isCreditNote ? 'ACREDITAR' : 'PAGAR' }}</td>
                    <td class="right mono">{{ $document->totalAmount()->format() }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<div class="legend">
    <table>
        <tr>
            <td style="width: 50%">
                <div><span class="muted">Emitida por:</span> {{ $document->issuedBy?->name ?? '—' }}</div>
                <div><span class="muted">Fecha y hora:</span> {{ $document->issued_at?->format('d/m/Y H:i') }}</div>
            </td>
            <td style="width: 50%" class="right">
                <div class="muted">Original: cliente · Copia: emisor</div>
                <div class="muted">Impreso el {{ $printedAt->format('d/m/Y H:i') }}</div>
            </td>
        </tr>
    </table>
</div>

<div class="slogan">LA FACTURA ES BENEFICIO DE TODOS, EXÍJALA</div>

</body>
</html>
