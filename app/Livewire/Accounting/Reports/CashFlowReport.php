<?php

declare(strict_types=1);

namespace App\Livewire\Accounting\Reports;

use App\Domains\Accounting\Services\CashFlowService;
use App\Domains\Reporting\DataTransfer\ReportColumn;
use App\Domains\Reporting\DataTransfer\ReportDocument;
use App\Domains\Reporting\DataTransfer\ReportRow;
use Illuminate\View\View;
use Livewire\Attributes\Title;

#[Title('Flujo de efectivo')]
class CashFlowReport extends ReportComponent
{
    public function render(CashFlowService $cashFlow): View
    {
        $this->authorize('accounting.reports.view');

        return view('livewire.accounting.reports.cash-flow', [
            'result' => $cashFlow->cashFlow($this->from, $this->to, $this->branchId),
            'branches' => $this->branches(),
        ]);
    }

    public function document(): ReportDocument
    {
        $result = app(CashFlowService::class)->cashFlow($this->from, $this->to, $this->branchId);
        $company = $this->company();

        $rows = [];

        foreach ($result['sections'] as $section) {
            $rows[] = ReportRow::group(['ACTIVIDADES DE '.mb_strtoupper($section['label']), null]);

            if ($section['rows'] === []) {
                $rows[] = ReportRow::detail(['Sin movimientos', null], indent: 1);
            }

            foreach ($section['rows'] as $line) {
                $rows[] = ReportRow::detail(
                    ["{$line['code']} — {$line['name']}", $line['amount']],
                    indent: 1,
                );
            }

            $rows[] = ReportRow::subtotal([
                'Efectivo neto de '.mb_strtolower($section['label']),
                $section['total'],
            ]);
            $rows[] = ReportRow::spacer(2);
        }

        $rows[] = ReportRow::subtotal(['VARIACIÓN NETA DEL EFECTIVO', $result['computed_change']]);
        $rows[] = ReportRow::detail(['Efectivo al inicio del período', $result['opening_cash']]);
        $rows[] = ReportRow::total(['EFECTIVO AL FINAL DEL PERÍODO', $result['closing_cash']]);

        return new ReportDocument(
            title: 'Estado de Flujo de Efectivo',
            companyName: $company->displayName(),
            companyTaxId: $company->tax_id,
            subtitle: $this->periodLabel(),
            columns: [
                ReportColumn::text('Concepto', 55),
                ReportColumn::amount('Importe', 20),
            ],
            rows: $rows,
            footnote: 'Método directo. Los importes positivos son entradas de efectivo y los negativos, salidas. '
                .'Los traslados entre cuentas de efectivo no se presentan porque no alteran el saldo total.',
            warning: $result['reconciled']
                ? null
                : 'El flujo calculado ('.$result['computed_change']->format().') no coincide con la variación real del efectivo ('
                    .$result['net_change']->format().'). Revise la clasificación de las cuentas.',
        );
    }
}
