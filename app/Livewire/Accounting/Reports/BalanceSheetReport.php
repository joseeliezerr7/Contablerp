<?php

declare(strict_types=1);

namespace App\Livewire\Accounting\Reports;

use App\Domains\Accounting\DataTransfer\StatementRow;
use App\Domains\Accounting\Services\FinancialStatementService;
use App\Domains\Reporting\DataTransfer\ReportColumn;
use App\Domains\Reporting\DataTransfer\ReportDocument;
use App\Domains\Reporting\DataTransfer\ReportRow;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Title;

/**
 * El balance general se toma a una fecha, no en un rango: `from` no se usa y la
 * fecha de corte es `to`.
 */
#[Title('Balance general')]
class BalanceSheetReport extends ReportComponent
{
    public function render(FinancialStatementService $statements): View
    {
        $this->authorize('accounting.reports.view');

        return view('livewire.accounting.reports.balance-sheet', [
            'result' => $statements->balanceSheet($this->to, $this->branchId),
            'branches' => $this->branches(),
        ]);
    }

    protected function periodLabel(): string
    {
        return 'Al '.CarbonImmutable::parse($this->to)->format('d/m/Y').$this->branchSuffix();
    }

    public function document(): ReportDocument
    {
        $result = app(FinancialStatementService::class)->balanceSheet($this->to, $this->branchId);
        $company = $this->company();

        $rows = [];

        $rows[] = ReportRow::group(['ACTIVO', null]);
        $this->appendSection($rows, $result['assets']);
        $rows[] = ReportRow::total(['TOTAL ACTIVO', $result['total_assets']]);
        $rows[] = ReportRow::spacer(2);

        $rows[] = ReportRow::group(['PASIVO', null]);
        $this->appendSection($rows, $result['liabilities']);
        $rows[] = ReportRow::subtotal(['Total pasivo', $result['total_liabilities']]);
        $rows[] = ReportRow::spacer(2);

        $rows[] = ReportRow::group(['PATRIMONIO', null]);
        $this->appendSection($rows, $result['equity']);

        if (! $result['profit']->isZero()) {
            $rows[] = ReportRow::detail([
                $result['profit']->isNegative() ? 'Pérdida del ejercicio' : 'Utilidad del ejercicio',
                $result['profit'],
            ], indent: 1);
        }

        $rows[] = ReportRow::subtotal([
            'Total patrimonio',
            $result['total_equity']->plus($result['profit']),
        ]);
        $rows[] = ReportRow::spacer(2);

        $rows[] = ReportRow::total([
            'TOTAL PASIVO Y PATRIMONIO',
            $result['total_liabilities_and_equity'],
        ]);

        return new ReportDocument(
            title: 'Balance General',
            companyName: $company->displayName(),
            companyTaxId: $company->tax_id,
            subtitle: $this->periodLabel(),
            columns: [
                ReportColumn::text('Concepto', 55),
                ReportColumn::amount('Importe', 20),
            ],
            rows: $rows,
            footnote: 'Expresado en '.$company->currency_code.'.',
            warning: $result['balanced']
                ? null
                : 'El balance no cuadra por '.$result['difference']->format().'. Revise el libro diario antes de usar este reporte.',
        );
    }

    /**
     * @param  array<int, ReportRow>  $rows
     * @param  array<int, array{code: string, name: string, total: Money, rows: array<int, StatementRow>}>  $section
     */
    private function appendSection(array &$rows, array $section): void
    {
        if ($section === []) {
            $rows[] = ReportRow::detail(['Sin saldos', null], indent: 1);

            return;
        }

        foreach ($section as $group) {
            $rows[] = ReportRow::detail([$group['name'], null], indent: 1);

            foreach ($group['rows'] as $line) {
                $rows[] = ReportRow::detail([$line->label(), $line->closing], indent: 2);
            }

            $rows[] = ReportRow::subtotal(['Total '.mb_strtolower($group['name']), $group['total']]);
        }
    }
}
