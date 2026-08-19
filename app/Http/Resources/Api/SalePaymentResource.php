<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Domains\Sales\Models\SalePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cobro aplicado al emitir.
 *
 * No expone `account_id`: la cuenta contable donde cae el dinero es una
 * decisión interna del sistema, y publicarla invita a que alguien intente
 * elegirla desde fuera.
 *
 * @mixin SalePayment
 */
class SalePaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'method' => $this->method->value,
            'amount' => $this->amountMoney()->toScale(2),
            'tendered' => $this->tenderedMoney()?->toScale(2),
            'change' => $this->changeMoney()?->toScale(2),
            'reference' => $this->reference,
        ];
    }
}
