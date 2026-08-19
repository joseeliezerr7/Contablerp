<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Domains\Partners\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'trade_name' => $this->trade_name,
            'tax_id' => $this->tax_id,
            'type' => $this->type,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'credit' => [
                'limit' => $this->creditLimit()->toScale(2),
                'days' => $this->credit_days,
                // El saldo se calcula, no se guarda: es la suma de lo que debe
                // hoy. Va aquí porque es la pregunta que hace todo integrador.
                'outstanding' => $this->outstandingBalance()->toScale(2),
            ],
            'is_active' => (bool) $this->is_active,
            'is_walk_in' => (bool) $this->is_walk_in,
        ];
    }
}
