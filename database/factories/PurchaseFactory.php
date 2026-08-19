<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Partners\Models\Supplier;
use App\Domains\Purchases\Enums\PurchaseStatus;
use App\Domains\Purchases\Models\Purchase;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    protected $model = Purchase::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'company_id' => fn (array $attributes) => Supplier::withoutGlobalScopes()
                ->findOrFail($attributes['supplier_id'])->company_id,
            'branch_id' => fn (array $attributes) => Branch::withoutGlobalScopes()
                ->where('company_id', $attributes['company_id'])->value('id'),
            'supplier_invoice_number' => 'FAC-'.fake()->unique()->numerify('######'),
            'date' => now()->toDateString(),
            'payment_condition' => PaymentCondition::Credit,
            'credit_days' => 30,
            'status' => PurchaseStatus::Draft,
        ];
    }
}
