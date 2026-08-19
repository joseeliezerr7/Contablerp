<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Domains\Sales\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SaleItem
 */
class SaleItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'line' => $this->line_number,
            'product_id' => $this->product_id,
            'description' => $this->description,
            // La cantidad y el precio unitario conservan la precisión con que
            // se capturaron —hay productos que se venden por kilo y precios de
            // cuatro decimales—; los importes salen a dos, como en el papel.
            'quantity' => (string) $this->quantity,
            'unit_price' => (string) $this->unit_price,
            'tax_rate' => (string) $this->tax_rate,
            'discount' => $this->discountAmount()->toScale(2),
            'tax' => $this->taxAmount()->toScale(2),
            'subtotal' => $this->subtotalAmount()->toScale(2),
            'total' => $this->totalAmount()->toScale(2),
        ];
    }
}
