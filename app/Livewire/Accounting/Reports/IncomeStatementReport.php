<?php

declare(strict_types=1);

namespace App\Livewire\Accounting\Reports;

use App\Domains\Accounting\DataTransfer\StatementRow;
use App\Domains\Accounting\Services\FinancialStatementService;
use App\Domains\Reporting\DataTransfer\ReportColumn;
use App\Domains\Reporting\DataTransfer\ReportDocument;
use App\Domains\Reporting\DataTransfer\ReportRow;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Title;

#[Title('Estado de resultados')]
class IncomeStatementReport extends ReportComponent
{
    public function render(FinancialStatementService $statements): View
    {
        $this->authorize('accounting.reports.view');

        return view('livewire.accounting.reports.income-statement', [
            'result' => $statements->incomeStatement($this->from, $this->to, $this->branchId),
            'branches' => $this->branches(),
        ]);
    }

    public function document(): ReportDocument
    {
        $result = app(FinancialStatementService::class)
            ->incomeStatement($this->from, $this->to, $this->branchId);

        $company = $this->company();
        $rows = [];

        $rows[] = ReportRow::group(['INGRESOS', null]);
        $this->appendDetail($rows, $result['income']);
        $rows[] = ReportRow::subtotal(['Total de ingresos', $result['total_income']]);
        $rows[] = ReportRow::spacer(2);

        $rows[] = ReportRow::group(['COSTOS', null]);
        $this->appendDetail($rows, $result['cost']);
        $rows[] = ReportRow::subtotal(['Total de costos', $result['total_cost']]);
        $rows[] = ReportRow::spacer(2);

        $rows[] = ReportRow::subtotal(['UTILIDAD BRUTA', $result['gross_profit']]);
        $rows[] = ReportRow::spacer(2);

        $rows[] = ReportRow::group(['GASTOS', null]);
        $this->appendDetail($rows, $result['expense']);
        $rows[] = ReportRow::subtotal(['Total de gastos', $result['total_expense']]);
        $rows[] = ReportRow::spacer(2);

        $rows[] = ReportRow::total([
            $result['net_profit']->isNegative() ? 'PÉRDIDA NETA DEL PERÍODO' : 'UTILIDAD NETA DEL PERÍODO',
            $result['net_profit'],
        ]);

        return new ReportDocument(
            title: 'Estado de Resultados',
            companyName: $company->displayName(),
            companyTaxId: $company->tax_id,
            subtitle: $this->periodLabel(),
            columns: [
                ReportColumn::text('Concepto', 55),
                ReportColumn::amount('Importe', 20),
            ],
            rows: $rows,
            footnote: 'Expresado en '.$company->currency_code.'. Excluye las partidas de cierre del ejercicio.',
        );
    }

    /**
     * @param  array<int, ReportRow>  $rows
     * @param  Collection<int, StatementRow>  $lines
     */
    private function appendDetail(array &$rows, Collection $lines): void
    {
        if ($lines->isEmpty()) {
            $rows[] = ReportRow::detail(['Sin movimientos', null], indent: 1);

            return;
        }

        foreach ($lines as $line) {
            $rows[] = ReportRow::detail([$line->label(), $line->closing], indent: 1);
        }
    }
}
