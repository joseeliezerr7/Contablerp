<?php

declare(strict_types=1);

namespace App\Livewire\Assets;

use App\Domains\Assets\Models\DepreciationRun;
use App\Support\Money;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * El desglose de una corrida mensual.
 *
 * La lista de corridas solo mostraba el total del mes. A la pregunta que
 * siempre llega —«¿por qué agosto dio 4 200 si julio dio 3 900?»— no había
 * respuesta: las líneas por activo estaban en `depreciation_run_lines` desde la
 * Fase 7 y ninguna pantalla las leía.
 */
#[Title('Corrida de depreciación')]
class DepreciationShow extends Component
{
    public int $runId;

    public function mount(int $run): void
    {
        $model = DepreciationRun::query()->findOrFail($run);

        $this->authorize('view', $model);

        $this->runId = $model->id;
    }

    public function render(): View
    {
        $run = DepreciationRun::query()
            ->with(['lines.asset:id,code,name,fixed_asset_category_id', 'lines.asset.category:id,code,name'])
            ->findOrFail($this->runId);

        $this->authorize('view', $run);

        return view('livewire.assets.depreciation-show', [
            'run' => $run,
            // Por categoría: es como lo lee un contador, que quiere saber cuánto
            // se fue en vehículos y cuánto en cómputo antes de mirar activo por
            // activo.
            'byCategory' => $run->lines
                ->groupBy(fn ($line) => $line->asset?->category?->name ?? 'Sin categoría')
                ->map(fn ($lines) => [
                    'count' => $lines->count(),
                    'total' => Money::sum($lines->map->amountMoney()->all()),
                ])
                ->sortKeys(),
            'entry' => $run->journalEntry(),
        ]);
    }
}
