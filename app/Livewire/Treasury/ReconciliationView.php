<?php

declare(strict_types=1);

namespace App\Livewire\Treasury;

use App\Domains\Treasury\Exceptions\TreasuryException;
use App\Domains\Treasury\Models\BankReconciliation;
use App\Domains\Treasury\Services\BankReconciliationService;
use App\Domains\Treasury\Services\CheckService;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Pantalla de marcado.
 *
 * El usuario tiene el extracto del banco a un lado y esta lista al otro, y va
 * marcando lo que aparece en ambos. Los cuatro números de arriba se recalculan
 * a cada marca, de modo que la diferencia pendiente está siempre a la vista:
 * es el único dato que dice cuánto falta por explicar.
 */
#[Title('Conciliación bancaria')]
class ReconciliationView extends Component
{
    public int $reconciliationId;

    public string $voidReason = '';

    public bool $showReopen = false;

    /**
     * Recibe el id y busca el modelo aquí dentro, en vez de dejar que Laravel
     * lo enlace desde la ruta.
     *
     * El enlace automático ocurre en `SubstituteBindings`, que corre **antes**
     * que el middleware que activa la empresa, así que la consulta saldría sin
     * contexto y el filtro por empresa la rechazaría. Es la misma convención
     * que siguen las demás pantallas con documento en la URL.
     */
    public function mount(int $reconciliation): void
    {
        $model = BankReconciliation::query()->findOrFail($reconciliation);

        $this->authorize('view', $model);
        $this->reconciliationId = $model->id;
    }

    public function toggle(int $lineId, BankReconciliationService $reconciliations): void
    {
        $reconciliation = $this->reconciliation();
        $this->authorize('update', $reconciliation);

        try {
            if (in_array($lineId, $reconciliations->markedIds($reconciliation), strict: true)) {
                $reconciliations->unmark($reconciliation, $lineId);
            } else {
                $reconciliations->mark($reconciliation, $lineId);
            }
        } catch (TreasuryException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function markAll(BankReconciliationService $reconciliations): void
    {
        $reconciliation = $this->reconciliation();
        $this->authorize('update', $reconciliation);

        try {
            $reconciliations->markAll($reconciliation);
        } catch (TreasuryException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function close(BankReconciliationService $reconciliations): void
    {
        $reconciliation = $this->reconciliation();
        $this->authorize('close', $reconciliation);

        try {
            $reconciliations->close($reconciliation);
            session()->flash('success', 'Conciliación cerrada.');
        } catch (TreasuryException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function reopen(BankReconciliationService $reconciliations): void
    {
        $this->validate([
            'voidReason' => ['required', 'string', 'min:5', 'max:500'],
        ], attributes: ['voidReason' => 'motivo']);

        $reconciliation = $this->reconciliation();
        $this->authorize('reopen', $reconciliation);

        try {
            $reconciliations->reopen($reconciliation, $this->voidReason);
            session()->flash('success', 'Conciliación reabierta.');
        } catch (TreasuryException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->cancelReopen();
    }

    public function confirmReopen(): void
    {
        $this->showReopen = true;
        $this->voidReason = '';
    }

    public function cancelReopen(): void
    {
        $this->reset(['showReopen', 'voidReason']);
        $this->resetValidation();
    }

    private function reconciliation(): BankReconciliation
    {
        return BankReconciliation::query()->findOrFail($this->reconciliationId);
    }

    public function render(BankReconciliationService $reconciliations, CheckService $checks): View
    {
        $reconciliation = $this->reconciliation();
        $this->authorize('view', $reconciliation);

        $reconciliation->load('bankAccount');

        return view('livewire.treasury.reconciliation-view', [
            'reconciliation' => $reconciliation,
            'items' => $reconciliations->items($reconciliation),
            'markedIds' => $reconciliations->markedIds($reconciliation),
            'outstandingChecks' => $checks->outstandingTotal(
                $reconciliation->bankAccount,
                $reconciliation->cutoff_date,
            ),
        ]);
    }
}
