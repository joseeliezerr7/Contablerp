<?php

declare(strict_types=1);

namespace App\Livewire\Accounting\Reports;

use App\Domains\Reporting\DataTransfer\ReportDocument;
use App\Domains\Reporting\Services\ReportExporter;
use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Base de los estados financieros: filtros comunes y exportación.
 *
 * Cada reporte solo tiene que saber construir su ReportDocument; de ahí salen
 * la pantalla, el PDF y el Excel, así que los tres muestran siempre lo mismo.
 */
abstract class ReportComponent extends Component
{
    #[Url(as: 'desde')]
    public string $from = '';

    #[Url(as: 'hasta')]
    public string $to = '';

    #[Url(as: 'sucursal', except: '')]
    public ?int $branchId = null;

    public function mount(): void
    {
        $this->from = $this->from ?: $this->defaultFrom();
        $this->to = $this->to ?: $this->defaultTo();
    }

    /**
     * Público a propósito: es la salida del reporte, y permite comprobar en las
     * pruebas que lo exportado coincide con lo que se ve en pantalla.
     */
    abstract public function document(): ReportDocument;

    public function exportPdf(ReportExporter $exporter)
    {
        $this->authorize('accounting.reports.export');

        return $exporter->pdf($this->document());
    }

    public function exportExcel(ReportExporter $exporter)
    {
        $this->authorize('accounting.reports.export');

        return $exporter->excel($this->document());
    }

    /**
     * @return Collection<int, Branch>
     */
    protected function branches(): Collection
    {
        return Branch::query()->active()->orderBy('code')->get();
    }

    protected function company(): Company
    {
        return app(CompanyContext::class)->companyOrFail();
    }

    /**
     * Texto del período que encabeza el reporte, incluida la sucursal si se
     * filtró: un balance de una sola sucursal no debe confundirse con el de la
     * empresa completa.
     */
    protected function periodLabel(): string
    {
        $from = CarbonImmutable::parse($this->from)->format('d/m/Y');
        $to = CarbonImmutable::parse($this->to)->format('d/m/Y');

        return "Del {$from} al {$to}".$this->branchSuffix();
    }

    protected function branchSuffix(): string
    {
        if ($this->branchId === null) {
            return '';
        }

        $branch = Branch::query()->find($this->branchId);

        return $branch === null ? '' : " · Sucursal {$branch->name}";
    }

    protected function defaultFrom(): string
    {
        return now()->startOfYear()->toDateString();
    }

    protected function defaultTo(): string
    {
        return now()->endOfMonth()->toDateString();
    }
}
