@php
    use App\Domains\Reporting\DataTransfer\ReportRow;
    use App\Support\Money;
@endphp

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $document->title }}</title>
    <style>
        @page { margin: 22mm 14mm 18mm 14mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #0f172a;
        }

        .encabezado { text-align: center; margin-bottom: 14px; }
        .encabezado .empresa { font-size: 13px; font-weight: bold; }
        .encabezado .rtn { font-size: 8px; color: #475569; }
        .encabezado .titulo { font-size: 11px; font-weight: bold; margin-top: 6px; }
        .encabezado .periodo { font-size: 9px; color: #475569; }

        .aviso {
            border: 1px solid #fca5a5;
            background: #fef2f2;
            color: #991b1b;
            padding: 5px 8px;
            margin-bottom: 10px;
            font-size: 8px;
        }

        table { width: 100%; border-collapse: collapse; }

        thead th {
            background: #e2e8f0;
            border-bottom: 1px solid #94a3b8;
            padding: 4px 5px;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        td { padding: 2.5px 5px; }

        tr.detail td { border-bottom: 1px solid #f1f5f9; }
        tr.group td { font-weight: bold; background: #f8fafc; padding-top: 5px; }
        tr.subtotal td { font-weight: bold; border-top: 1px solid #cbd5e1; }
        tr.total td { font-weight: bold; border-top: 1.5px solid #475569; border-bottom: 1.5px solid #475569; }
        tr.spacer td { height: 7px; border: none; }

        .derecha { text-align: right; }
        .izquierda { text-align: left; }
        .negativo { color: #b91c1c; }

        .pie {
            margin-top: 16px;
            font-size: 8px;
            color: #64748b;
            font-style: italic;
        }

        .firmas {
            margin-top: 42px;
            width: 100%;
        }

        .firmas td {
            text-align: center;
            font-size: 8px;
            padding-top: 4px;
            border-top: 1px solid #0f172a;
            width: 33%;
        }

        .firmas .separador { border: none; }
    </style>
</head>
<body>

<div class="encabezado">
    <div class="empresa">{{ $document->companyName }}</div>
    <div class="rtn">RTN {{ $document->companyTaxId }}</div>
    <div class="titulo">{{ $document->title }}</div>
    <div class="periodo">{{ $document->subtitle }}</div>
</div>

@if ($document->warning)
    <div class="aviso">{{ $document->warning }}</div>
@endif

<table>
    <thead>
        <tr>
            @foreach ($document->columns as $column)
                <th class="{{ $column->isAmount() ? 'derecha' : 'izquierda' }}">{{ $column->label }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($document->rows as $row)
            <tr class="{{ $row->style }}">
                @foreach ($row->cells as $index => $cell)
                    @php $column = $document->columns[$index] ?? null; @endphp
                    <td class="{{ $column?->isAmount() ? 'derecha' : 'izquierda' }}">
                        @if ($cell instanceof Money)
                            <span class="{{ $cell->isNegative() ? 'negativo' : '' }}">
                                {{ $cell->isZero() && $row->style === ReportRow::DETAIL ? '' : $cell->format() }}
                            </span>
                        @elseif ($cell !== null)
                            {!! str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $row->indent) !!}{{ $cell }}
                        @endif
                    </td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>

@if ($document->footnote)
    <div class="pie">{{ $document->footnote }}</div>
@endif

<table class="firmas">
    <tr>
        <td>Elaborado por</td>
        <td class="separador"></td>
        <td>Revisado por</td>
    </tr>
</table>

</body>
</html>
