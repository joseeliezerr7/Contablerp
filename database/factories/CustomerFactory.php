<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Partners\Models\Customer;
use App\Domains\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => 'CLI'.fake()->unique()->numerify('####'),
            'name' => fake()->company(),
            'tax_id' => (string) fake()->numerify('##############'),
            'type' => 'company',
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->numerify('####-####'),
            'address' => fake()->address(),
            'credit_limit' => 0,
            'credit_days' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Cliente con crédito autorizado.
     */
    public function withCredit(string $limit = '100000.00', int $days = 30): static
    {
        return $this->state(fn (array $attributes) => [
            'credit_limit' => $limit,
            'credit_days' => $days,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function forCompany(Company|int $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company instanceof Company ? $company->id : $company,
        ]);
    }
}
