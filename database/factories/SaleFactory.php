<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Partners\Models\Customer;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Models\Sale;
use App\Domains\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Crea la factura en crudo, sin pasar por SaleService. Sirve para probar
 * consultas y permisos; el flujo real —numeración, cuenta por cobrar y
 * partida— hay que ejercitarlo con el servicio.
 *
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'company_id' => fn (array $attributes) => Customer::withoutGlobalScopes()
                ->findOrFail($attributes['customer_id'])->company_id,
            'branch_id' => fn (array $attributes) => Branch::withoutGlobalScopes()
                ->where('company_id', $attributes['company_id'])->value('id')
                ?? Branch::factory()->forCompany($attributes['company_id'])->create()->id,
            'date' => now()->toDateString(),
            'payment_condition' => PaymentCondition::Cash,
            'status' => SaleStatus::Draft,
        ];
    }

    public function onCredit(int $days = 30): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_condition' => PaymentCondition::Credit,
            'credit_days' => $days,
        ]);
    }
}
