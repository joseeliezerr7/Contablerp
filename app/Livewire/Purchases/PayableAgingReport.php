<?php

declare(strict_types=1);

namespace App\Livewire\Purchases;

use App\Domains\Payables\Services\PayableService;
use App\Domains\Reporting\DataTransfer\ReportColumn;
use App\Domains\Reporting\DataTransfer\ReportDocument;
use App\Domains\Reporting\DataTransfer\ReportRow;
use App\Domains\Reporting\Services\ReportExporter;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Antigüedad de saldos por pagar')]
class PayableAgingReport extends Component
{
    #[Url(as: 'al')]
    public string $asOf = '';

    public function mount(): void
    {
        $this->asOf = $this->asOf ?: now()->toDateString();
    }

    public function exportPdf(ReportExporter $exporter)
    {
        $this->authorize('payables.reports');

        return $exporter->pdf($this->document());
    }

    public function exportExcel(ReportExporter $exporter)
    {
        $this->authorize('payables.reports');

        return $exporter->excel($this->document());
    }

    public function document(): ReportDocument
    {
        $result = app(PayableService::class)->aging($this->asOf);
        $company = app(CompanyContext::class)->companyOrFail();

        $rows = [];

        foreach ($result['rows'] as $row) {
            $rows[] = ReportRow::detail([
                $row['supplier']->code,
                $row['supplier']->name,
                $row['current'],
                $row['d30'],
                $row['d60'],
                $row['d90'],
                $row['over'],
                $row['total'],
            ]);
        }

        $rows[] = ReportRow::total([
            '', 'TOTALES',
            $result['totals']['current'],
            $result['totals']['d30'],
            $result['totals']['d60'],
            $result['totals']['d90'],
            $result['totals']['over'],
            $result['totals']['total'],
        ]);

        return new ReportDocument(
            title: 'Antigüedad de Saldos por Pagar',
            companyName: $company->displayName(),
            companyTaxId: $company->tax_id,
            subtitle: 'Al '.CarbonImmutable::parse($this->asOf)->format('d/m/Y'),
            columns: [
                ReportColumn::text('Código', 12),
                ReportColumn::text('Proveedor', 38),
                ReportColumn::amount('Corriente'),
                ReportColumn::amount('1–30'),
                ReportColumn::amount('31–60'),
                ReportColumn::amount('61–90'),
                ReportColumn::amount('Más de 90'),
                ReportColumn::amount('Total'),
            ],
            rows: $rows,
            footnote: 'Los tramos se calculan sobre la fecha de vencimiento de cada documento.',
        );
    }

    public function render(PayableService $payables): View
    {
        $this->authorize('payables.reports');

        return view('livewire.purchases.payable-aging', [
            'result' => $payables->aging($this->asOf),
        ]);
    }
}
