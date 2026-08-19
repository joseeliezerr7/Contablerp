<?php

declare(strict_types=1);

namespace App\Livewire\Accounting\Reports;

use App\Domains\Accounting\DataTransfer\StatementRow;
use App\Domains\Accounting\Services\FinancialStatementService;
use App\Domains\Reporting\DataTransfer\ReportColumn;
use App\Domains\Reporting\DataTransfer\ReportDocument;
use App\Domains\Reporting\DataTransfer\ReportRow;
use Illuminate\View\View;
use Livewire\Attributes\Title;

#[Title('Balance de comprobación')]
class TrialBalanceReport extends ReportComponent
{
    public function render(FinancialStatementService $statements): View
    {
        $this->authorize('accounting.reports.view');

        return view('livewire.accounting.reports.trial-balance', [
            'result' => $this->result($statements),
            'branches' => $this->branches(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function result(FinancialStatementService $statements): array
    {
        return $statements->trialBalance($this->from, $this->to, $this->branchId);
    }

    public function document(): ReportDocument
    {
        $result = $this->result(app(FinancialStatementService::class));
        $company = $this->company();

        $rows = [];

        foreach ($result['rows'] as $row) {
            /** @var StatementRow $row */
            $rows[] = ReportRow::detail([
                $row->code,
                $row->name,
                $row->opening,
                $row->debit,
                $row->credit,
                $row->debitBalance(),
                $row->creditBalance(),
            ]);
        }

        $rows[] = ReportRow::total([
            '',
            'TOTALES',
            null,
            $result['debit'],
            $result['credit'],
            $result['closing_debit'],
            $result['closing_credit'],
        ]);

        return new ReportDocument(
            title: 'Balance de Comprobación',
            companyName: $company->displayName(),
            companyTaxId: $company->tax_id,
            subtitle: $this->periodLabel(),
            columns: [
                ReportColumn::text('Código', 14),
                ReportColumn::text('Cuenta', 42),
                ReportColumn::amount('Saldo inicial'),
                ReportColumn::amount('Debe'),
                ReportColumn::amount('Haber'),
                ReportColumn::amount('Saldo deudor'),
                ReportColumn::amount('Saldo acreedor'),
            ],
            rows: $rows,
            footnote: 'Incluye únicamente partidas contabilizadas.',
            warning: $result['balanced']
                ? null
                : 'Este balance no cuadra. Ejecute «accounting:rebuild-balances --check» y revise el libro diario.',
        );
    }
}
