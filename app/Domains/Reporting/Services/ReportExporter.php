<?php

declare(strict_types=1);

namespace App\Domains\Reporting\Services;

use App\Domains\Reporting\DataTransfer\ReportDocument;
use App\Domains\Reporting\DataTransfer\ReportRow;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Convierte un ReportDocument en PDF o en hoja de cálculo.
 *
 * En Excel los importes se escriben como número, no como texto ya formateado:
 * un contador que exporta un balance espera poder sumar la columna.
 */
final class ReportExporter
{
    /**
     * Descarga por streaming y no `Pdf::download()`: esa devuelve una Response
     * con cuerpo binario que Livewire intenta serializar como JSON y falla con
     * «Malformed UTF-8 characters». Livewire sí reconoce StreamedResponse.
     */
    public function pdf(ReportDocument $document): StreamedResponse
    {
        $pdf = Pdf::loadView('reports.pdf', ['document' => $document])
            ->setPaper('letter', $this->orientationFor($document));

        return response()->streamDownload(
            function () use ($pdf): void {
                echo $pdf->output();
            },
            $document->filename('pdf'),
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'no-store, no-cache',
            ],
        );
    }

    public function excel(ReportDocument $document): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($document->title, 0, 31));

        $columnCount = count($document->columns);
        $lastColumn = $this->columnLetter($columnCount);

        $row = $this->writeHeader($sheet, $document, $lastColumn);
        $row = $this->writeColumnTitles($sheet, $document, $row, $lastColumn);
        $this->writeRows($sheet, $document, $row);

        foreach ($document->columns as $index => $column) {
            $sheet->getColumnDimension($this->columnLetter($index + 1))->setWidth($column->width);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(
            function () use ($writer): void {
                $writer->save('php://output');
            },
            $document->filename('xlsx'),
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, no-cache',
            ],
        );
    }

    private function writeHeader($sheet, ReportDocument $document, string $lastColumn): int
    {
        $lines = [
            [$document->companyName, 14, true],
            ['RTN '.$document->companyTaxId, 10, false],
            [$document->title, 12, true],
            [$document->subtitle, 10, false],
        ];

        $row = 1;

        foreach ($lines as [$text, $size, $bold]) {
            $sheet->setCellValue("A{$row}", $text);
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setBold($bold)->setSize($size);
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        return $row + 1;
    }

    private function writeColumnTitles($sheet, ReportDocument $document, int $row, string $lastColumn): int
    {
        foreach ($document->columns as $index => $column) {
            $cell = $this->columnLetter($index + 1).$row;
            $sheet->setCellValue($cell, $column->label);
            $sheet->getStyle($cell)->getAlignment()->setHorizontal(
                $column->isAmount() ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT
            );
        }

        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E2E8F0');
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getBorders()->getBottom()
            ->setBorderStyle(Border::BORDER_THIN);

        return $row + 1;
    }

    private function writeRows($sheet, ReportDocument $document, int $row): void
    {
        foreach ($document->rows as $reportRow) {
            if ($reportRow->style === ReportRow::SPACER) {
                $row++;

                continue;
            }

            foreach ($reportRow->cells as $index => $value) {
                $letter = $this->columnLetter($index + 1);
                $cell = $letter.$row;
                $column = $document->columns[$index] ?? null;

                if ($value instanceof Money) {
                    // Número real, no texto: la columna debe poder sumarse.
                    $sheet->setCellValue($cell, (float) $value->round(2)->toString());
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');
                } elseif ($value !== null) {
                    $indent = str_repeat('    ', $reportRow->indent);
                    $sheet->setCellValueExplicit(
                        $cell,
                        $indent.$value,
                        DataType::TYPE_STRING,
                    );
                }

                if ($column?->isAmount()) {
                    $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
            }

            if ($reportRow->isEmphasised()) {
                $last = $this->columnLetter(count($document->columns));
                $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);
            }

            if ($reportRow->style === ReportRow::TOTAL) {
                $last = $this->columnLetter(count($document->columns));
                $sheet->getStyle("A{$row}:{$last}{$row}")->getBorders()->getTop()
                    ->setBorderStyle(Border::BORDER_THIN);
            }

            $row++;
        }

        if ($document->footnote !== null) {
            $row++;
            $sheet->setCellValue("A{$row}", $document->footnote);
            $sheet->getStyle("A{$row}")->getFont()->setSize(9)->setItalic(true);
        }
    }

    private function columnLetter(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    /**
     * Los reportes de muchas columnas (balance de comprobación) se imprimen
     * apaisados; los demás, verticales.
     */
    private function orientationFor(ReportDocument $document): string
    {
        return count($document->columns) > 4 ? 'landscape' : 'portrait';
    }
}
