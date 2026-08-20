<?php

declare(strict_types=1);

namespace App\Livewire\Assets;

use App\Domains\Assets\Models\FixedAsset;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * La ficha del activo, con su historia de depreciación.
 *
 * `depreciation_run_lines` guarda una fila por activo y por mes,
 * con la cuota, la acumulada y el valor en libros de ese momento. Nunca hubo
 * pantalla que la leyera: el listado solo mostraba el acumulado de hoy, así que
 * a la pregunta «¿desde cuándo se está depreciando esto y cuánto llevaba en
 * marzo?» no había cómo responder sin entrar a la base.
 */
#[Title('Activo fijo')]
class FixedAssetShow extends Component
{
    public int $assetId;

    /**
     * El id se busca aquí y no por enlace de ruta: `SubstituteBindings` corre
     * antes del middleware que activa la empresa.
     */
    public function mount(int $asset): void
    {
        $model = FixedAsset::query()->findOrFail($asset);

        $this->authorize('view', $model);

        $this->assetId = $model->id;
    }

    public function render(): View
    {
        $asset = FixedAsset::query()
            ->with([
                'category.assetAccount:id,code,name',
                'category.depreciationAccount:id,code,name',
                'category.accumulatedAccount:id,code,name',
                'branch:id,code,name',
                // Las corridas anuladas se muestran igual, tachadas: su cuota ya
                // no cuenta, pero esconderlas dejaría huecos inexplicables en la
                // historia del activo.
                'depreciationLines.run:id,number,period_month,posted_on,status',
            ])
            ->findOrFail($this->assetId);

        $this->authorize('view', $asset);

        return view('livewire.assets.fixed-asset-show', [
            'asset' => $asset,
            'lines' => $asset->depreciationLines
                ->sortByDesc(fn ($line) => $line->run?->period_month)
                ->values(),
        ]);
    }
}
