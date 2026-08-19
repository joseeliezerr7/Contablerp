<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Siempre explícito: el trait BelongsToCompany falla si no hay
            // empresa en el contexto, y las factories corren sin petición HTTP.
            'company_id' => Company::factory(),
            'code' => (string) fake()->unique()->numerify('##0'),
            'name' => 'Sucursal '.fake()->city(),
            'address' => fake()->address(),
            'phone' => fake()->numerify('####-####'),
            'is_main' => false,
            'is_active' => true,
        ];
    }

    public function main(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => '001',
            'name' => 'Casa Matriz',
            'is_main' => true,
        ]);
    }

    public function forCompany(Company|int $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company instanceof Company ? $company->id : $company,
        ]);
    }
}
