<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Domains\Sales\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Factura de venta.
 *
 * Incluye el bloque `fiscal` con lo que quedó congelado en el documento: es lo
 * que un integrador necesita para reimprimir o para archivar, y sale del propio
 * documento, no de la autorización vigente.
 *
 * @mixin Sale
 */
class SaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'date' => $this->date->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'payment_condition' => $this->payment_condition->value,
            'currency' => $this->currency_code,

            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'tax_id' => $this->customer->tax_id,
            ]),

            'fiscal' => $this->when($this->number !== null, fn () => [
                'cai' => $this->cai,
                'range_from' => $this->fiscal_range_from,
                'range_to' => $this->fiscal_range_to,
                'sequence' => $this->fiscal_sequence,
                'limit_date' => $this->fiscal_limit_date?->toDateString(),
            ]),

            // Importes a dos decimales, los del documento. La escala interna de
            // cuatro es del motor, no de quien integra.
            'totals' => [
                'subtotal' => $this->subtotalAmount()->toScale(2),
                'discount' => $this->discountAmount()->toScale(2),
                'tax' => $this->taxAmount()->toScale(2),
                'total' => $this->totalAmount()->toScale(2),
                // Lo que queda por cobrar después de recibos y notas de crédito.
                'balance' => $this->receivable?->balanceAmount()->toScale(2),
            ],

            'items' => SaleItemResource::collection($this->whenLoaded('items')),
            'payments' => SalePaymentResource::collection($this->whenLoaded('payments')),

            'issued_at' => $this->issued_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
        ];
    }
}
