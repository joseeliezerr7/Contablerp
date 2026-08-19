<?php

declare(strict_types=1);

namespace App\Livewire\Assets;

use App\Domains\Assets\Exceptions\AssetException;
use App\Domains\Assets\Models\DepreciationRun;
use App\Domains\Assets\Services\DepreciationService;
use App\Support\Money;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Corridas de depreciación.
 *
 * La pantalla muestra primero la vista previa del mes: el contador ve activo
 * por activo lo que se va a depreciar **antes** de generar la partida. Es lo
 * que convierte la corrida en una decisión y no en un botón a ciegas.
 */
#[Title('Depreciación')]
class DepreciationIndex extends Component
{
    use WithPagination;

    public string $period = '';

    public ?int $voidingId = null;

    public string $voidReason = '';

    public function mount(): void
    {
        $this->period = now()->startOfMonth()->toDateString();
    }

    public function run(DepreciationService $depreciation): void
    {
        $this->authorize('create', DepreciationRun::class);

        $this->validate([
            'period' => ['required', 'date'],
        ], attributes: ['period' => 'mes']);

        try {
            $run = $depreciation->run($this->period);
            session()->flash('success', "Depreciación {$run->number} generada por {$run->totalAmount()->format()}.");
        } catch (AssetException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function confirmVoid(int $id): void
    {
        $run = DepreciationRun::query()->findOrFail($id);
        $this->authorize('void', $run);

        $this->voidingId = $id;
        $this->voidReason = '';
    }

    public function void(DepreciationService $depreciation): void
    {
        $this->validate([
            'voidReason' => ['required', 'string', 'min:5', 'max:500'],
        ], attributes: ['voidReason' => 'motivo']);

        $run = DepreciationRun::query()->findOrFail($this->voidingId);
        $this->authorize('void', $run);

        try {
            $depreciation->void($run, $this->voidReason);
            session()->flash('success', 'Corrida anulada.');
        } catch (AssetException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->cancelVoid();
    }

    public function cancelVoid(): void
    {
        $this->reset(['voidingId', 'voidReason']);
        $this->resetValidation();
    }

    public function render(DepreciationService $depreciation): View
    {
        $this->authorize('viewAny', DepreciationRun::class);

        $preview = [];
        $previewTotal = Money::zero();

        try {
            $preview = $depreciation->preview($this->period);
            $previewTotal = Money::sum(array_map(fn (array $row) => $row['amount'], $preview));
        } catch (\Throwable) {
            // Una fecha a medio escribir no debe romper la pantalla.
        }

        return view('livewire.assets.depreciation-index', [
            'runs' => DepreciationRun::query()->orderByDesc('period_month')->paginate(24),
            'preview' => $preview,
            'previewTotal' => $previewTotal,
            'alreadyRun' => DepreciationRun::query()
                ->where('status', 'posted')
                ->whereDate('period_month', $this->period)
                ->exists(),
        ]);
    }
}
