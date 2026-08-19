<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Domains\Catalog\Models\PriceList;
use App\Domains\Catalog\Models\Product;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Producto tal como lo ve la API.
 *
 * Los importes salen como **cadenas**, no como números. Un JSON con
 * `210.00` obliga al cliente a parsearlo como float, y ahí se pierde el
 * centavo que el sistema entero se ha cuidado de no perder. Como cadena, quien
 * consume decide con qué precisión trabajar.
 *
 * El costo no se expone: es información interna y quien pregunta por la API casi
 * nunca es quien tiene derecho a verla.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $price = $this->priceIn(PriceList::default()?->id);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'type' => $this->type,
            'price' => ($price ?? Money::of($this->price ?? '0'))->toScale(2),
            'tax' => $this->whenLoaded('tax', fn () => [
                'id' => $this->tax->id,
                'code' => $this->tax->code,
                'rate' => (string) $this->tax->rate,
            ]),
            'tracks_inventory' => (bool) $this->track_inventory,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
