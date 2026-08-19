<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Partners\Models\Supplier;
use App\Domains\Payables\Models\Payment;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

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
            'number' => 'PAG-'.fake()->unique()->numerify('######'),
            'date' => now()->toDateString(),
            'payment_method' => PaymentMethod::Transfer,
            'amount' => '0',
            'status' => 'issued',
        ];
    }
}
