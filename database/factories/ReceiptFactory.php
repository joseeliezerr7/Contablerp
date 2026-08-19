<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Partners\Models\Customer;
use App\Domains\Receivables\Models\Receipt;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Receipt>
 */
class ReceiptFactory extends Factory
{
    protected $model = Receipt::class;

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
                ->where('company_id', $attributes['company_id'])->value('id'),
            'number' => 'REC-'.fake()->unique()->numerify('######'),
            'date' => now()->toDateString(),
            'payment_method' => PaymentMethod::Cash,
            'amount' => '0',
            'status' => 'issued',
        ];
    }
}
