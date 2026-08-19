<?php

declare(strict_types=1);

namespace App\Domains\Fiscal\Services;

use App\Domains\Sales\Models\CreditNote;
use App\Domains\Sales\Models\Sale;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Impresión de documentos fiscales.
 *
 * Todo lo que se imprime del régimen —CAI, rango autorizado, fecha límite— sale
 * de lo que quedó **congelado en el documento**, nunca de la autorización
 * vigente. Reimprimir una factura de hace dos años tiene que dar exactamente el
 * papel que se entregó entonces; si se leyera de la autorización de hoy,
 * mostraría un CAI que esa factura nunca llevó, y eso ya no es una reimpresión
 * sino un documento distinto.
 */
final class DocumentPrinter
{
    public function __construct(private readonly CompanyContext $context) {}

    public function invoice(Sale $sale): StreamedResponse
    {
        return $this->stream($sale, 'invoice', 'Factura');
    }

    public function creditNote(CreditNote $note): StreamedResponse
    {
        return $this->stream($note, 'credit_note', 'Nota de Crédito');
    }

    /**
     * El HTML del documento, para poder verlo en pantalla y para poder probarlo
     * sin generar un PDF.
     */
    public function render(Model $document, string $kind, string $title): string
    {
        return view('fiscal.document', $this->data($document, $kind, $title))->render();
    }

    /**
     * Descarga por streaming, no `Pdf::download()`: esa devuelve un cuerpo
     * binario que Livewire intenta serializar como JSON y rompe con «Malformed
     * UTF-8 characters». Mismo motivo que en el exportador de reportes.
     */
    private function stream(Model $document, string $kind, string $title): StreamedResponse
    {
        $pdf = Pdf::loadView('fiscal.document', $this->data($document, $kind, $title))
            ->setPaper('letter', 'portrait');

        $filename = str_replace('-', '', (string) $document->number).'.pdf';

        return response()->streamDownload(
            function () use ($pdf): void {
                echo $pdf->output();
            },
            mb_strtolower(str_replace(' ', '-', $title)).'-'.$filename,
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'no-store, no-cache',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Model $document, string $kind, string $title): array
    {
        $document->loadMissing(['items.tax', 'customer', 'branch', 'issuedBy']);

        if ($kind === 'credit_note') {
            $document->loadMissing('sale');
        }

        return [
            'document' => $document,
            'kind' => $kind,
            'title' => $title,
            'company' => $this->context->companyOrFail(),
            'branch' => $document->branch,
            'customer' => $document->customer,
            'rangeLabel' => $this->rangeLabel($document),
            'taxableTotal' => $this->taxableTotal($document),
            'exemptTotal' => $this->exemptTotal($document),
            'taxBreakdown' => $this->taxBreakdown($document),
            'printedAt' => now(),
        ];
    }

    /**
     * `000-001-01-00000001 al 000-001-01-00005000`, armado con el rango
     * congelado en el documento.
     */
    private function rangeLabel(Model $document): string
    {
        $number = (string) $document->number;
        $from = $document->fiscal_range_from;
        $to = $document->fiscal_range_to;

        if ($from === null || $number === '') {
            return '—';
        }

        $prefix = mb_substr($number, 0, (int) mb_strrpos($number, '-') + 1);

        return $prefix.str_pad((string) $from, 8, '0', STR_PAD_LEFT)
            .' al '.$prefix.str_pad((string) $to, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Base gravada: las líneas que llevan impuesto.
     *
     * El SAR pide gravado y exento por separado, y no se pueden deducir del
     * total: dos facturas con el mismo total pueden tener repartos distintos.
     */
    private function taxableTotal(Model $document): Money
    {
        return Money::sum(
            $document->items
                ->filter(fn ($item) => ! $item->taxAmount()->isZero())
                ->map(fn ($item) => $item->subtotalAmount())
                ->all()
        );
    }

    private function exemptTotal(Model $document): Money
    {
        return Money::sum(
            $document->items
                ->filter(fn ($item) => $item->taxAmount()->isZero())
                ->map(fn ($item) => $item->subtotalAmount())
                ->all()
        );
    }

    /**
     * Impuestos agrupados por tasa.
     *
     * Una factura puede llevar ISV 15 % y 18 % a la vez —el 18 % aplica a
     * ciertos bienes—, y el desglose tiene que mostrarlos separados: sumarlos en
     * una sola línea impediría comprobar el cálculo.
     *
     * @return array<int, array{label: string, amount: Money}>
     */
    private function taxBreakdown(Model $document): array
    {
        $groups = [];

        foreach ($document->items as $item) {
            if ($item->taxAmount()->isZero()) {
                continue;
            }

            $rate = rtrim(rtrim(number_format((float) $item->tax_rate, 2, '.', ''), '0'), '.');
            $name = $item->tax?->name ?? 'Impuesto sobre ventas';

            // Los impuestos hondureños suelen llamarse «ISV 15%», con la tasa ya
            // dentro del nombre. Pegársela otra vez imprimía «ISV 15% 15 %» en
            // la factura.
            $label = str_contains($name, $rate) ? $name : $name.' '.$rate.' %';

            $groups[$label] = ($groups[$label] ?? Money::zero())->plus($item->taxAmount());
        }

        ksort($groups);

        return array_map(
            fn (string $label, Money $amount) => ['label' => $label, 'amount' => $amount],
            array_keys($groups),
            array_values($groups),
        );
    }
}
