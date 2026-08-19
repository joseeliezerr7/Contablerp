<?php

declare(strict_types=1);

namespace App\Livewire\Accounting\Reports;

use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Exceptions\AccountingException;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\ClosingService;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Índice de reportes y cierre del ejercicio.
 *
 * El cierre vive aquí, junto a los estados financieros, porque es la operación
 * que se hace justo después de revisarlos.
 */
#[Title('Centro de reportes')]
class ReportCenter extends Component
{
    public ?int $closingYearId = null;

    public function confirmClose(int $fiscalYearId): void
    {
        $this->authorize('accounting.periods.close');

        $this->closingYearId = $fiscalYearId;
    }

    public function cancelClose(): void
    {
        $this->closingYearId = null;
    }

    public function closeYear(ClosingService $closing): void
    {
        $this->authorize('accounting.periods.close');

        $year = FiscalYear::query()->findOrFail($this->closingYearId);

        try {
            $result = $closing->closeFiscalYear($year, auth()->id());

            session()->flash('success', sprintf(
                'Ejercicio %s cerrado. Resultado del período: %s. Partidas generadas: %s.',
                $year->name,
                $result['net_profit']->format(),
                $result['entries'] === []
                    ? 'ninguna'
                    : implode(', ', array_map(fn ($e) => $e->number, $result['entries'])),
            ));
        } catch (AccountingException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->closingYearId = null;
    }

    public function render(ClosingService $closing): View
    {
        $this->authorize('accounting.reports.view');

        $years = FiscalYear::query()->orderByDesc('starts_on')->get();

        return view('livewire.accounting.reports.center', [
            'fiscalYears' => $years,
            'blockers' => $years
                ->filter(fn (FiscalYear $y) => $y->status === FiscalYearStatus::Open)
                ->mapWithKeys(fn (FiscalYear $y) => [$y->id => $closing->blockers($y)]),
        ]);
    }
}
