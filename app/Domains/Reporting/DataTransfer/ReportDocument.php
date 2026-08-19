<?php

declare(strict_types=1);

namespace App\Domains\Reporting\DataTransfer;

/**
 * Representación neutral de un reporte, independiente del formato de salida.
 *
 * Los estados financieros la construyen una sola vez y de ahí salen tanto el
 * PDF como el Excel. Sin esta capa habría que mantener el mismo reporte escrito
 * tres veces —pantalla, PDF y hoja de cálculo— y acabarían divergiendo.
 */
final readonly class ReportDocument
{
    /**
     * @param  array<int, ReportColumn>  $columns
     * @param  array<int, ReportRow>  $rows
     */
    public function __construct(
        public string $title,
        public string $companyName,
        public string $companyTaxId,
        public string $subtitle,
        public array $columns,
        public array $rows,
        public ?string $footnote = null,
        public ?string $warning = null,
    ) {}

    public function filename(string $extension): string
    {
        $slug = str($this->title)->slug()->value();
        $date = now()->format('Y-m-d');

        return "{$slug}-{$date}.{$extension}";
    }
}
