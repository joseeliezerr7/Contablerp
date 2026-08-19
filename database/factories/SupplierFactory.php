<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Partners\Models\Supplier;
use App\Domains\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => 'PRV'.fake()->unique()->numerify('####'),
            'name' => fake()->company(),
            'tax_id' => (string) fake()->numerify('##############'),
            'type' => 'company',
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->numerify('####-####'),
            'credit_days' => 30,
            'is_active' => true,
        ];
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
