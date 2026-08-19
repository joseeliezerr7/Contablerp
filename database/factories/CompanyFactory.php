<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Tenancy\Models\Company;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $legalName = fake()->company().', S. de R.L.';

        return [
            'tenant_id' => Tenant::factory(),
            'legal_name' => $legalName,
            'trade_name' => fake()->company(),
            // RTN hondureño: 14 dígitos.
            'tax_id' => (string) fake()->unique()->numerify('##############'),
            'address' => fake()->address(),
            'phone' => fake()->numerify('####-####'),
            'email' => fake()->unique()->companyEmail(),
            'country_code' => 'HN',
            'currency_code' => 'HNL',
            'locale' => 'es',
            'fiscal_year_start_month' => 1,
            'decimal_places' => 2,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * Crea la empresa junto con su sucursal principal y su bodega por defecto,
     * que es como nace una empresa real en el sistema.
     */
    public function withMainBranch(): static
    {
        return $this->afterCreating(function (Company $company): void {
            $branch = $company->branches()->create([
                'code' => '001',
                'name' => 'Casa Matriz',
                'is_main' => true,
                'is_active' => true,
            ]);

            $company->warehouses()->create([
                'branch_id' => $branch->id,
                'code' => 'BOD01',
                'name' => 'Bodega Principal',
                'is_default' => true,
                'is_active' => true,
            ]);
        });
    }
}
