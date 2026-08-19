<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Domains\Accounting\Exceptions\AccountingException;
use App\Domains\Accounting\Models\AccountingPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PeriodService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Los permisos de spatie se registran como habilidades del Gate, así que
 * `authorize('accounting.periods.close')` comprueba el permiso del rol en la
 * empresa activa. No hay modelo que autorizar aquí: el período ya viene
 * filtrado por el scope global.
 */
#[Title('Períodos contables')]
class PeriodIndex extends Component
{
    public int $newYear;

    public function mount(): void
    {
        $this->newYear = (int) now()->format('Y') + 1;
    }

    public function createFiscalYear(PeriodService $service): void
    {
        $this->authorize('accounting.periods.create');

        try {
            $company = app(CompanyContext::class)->companyOrFail();
            $service->createFiscalYear($company, $this->newYear);
            session()->flash('success', "Ejercicio fiscal {$this->newYear} creado con sus doce períodos.");
        } catch (AccountingException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function close(int $periodId, PeriodService $service): void
    {
        $this->authorize('accounting.periods.close');

        $period = AccountingPeriod::query()->findOrFail($periodId);

        try {
            $service->close($period, auth()->id());
            session()->flash('success', "Período {$period->name} cerrado.");
        } catch (AccountingException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function reopen(int $periodId, PeriodService $service): void
    {
        $this->authorize('accounting.periods.reopen');

        $period = AccountingPeriod::query()->findOrFail($periodId);

        try {
            $service->reopen($period);
            session()->flash('success', "Período {$period->name} reabierto.");
        } catch (AccountingException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $this->authorize('accounting.periods.view');

        return view('livewire.accounting.period-index', [
            'fiscalYears' => FiscalYear::query()
                ->with(['periods' => fn ($query) => $query->withCount('journalEntries')])
                ->orderByDesc('starts_on')
                ->get(),
        ]);
    }
}
