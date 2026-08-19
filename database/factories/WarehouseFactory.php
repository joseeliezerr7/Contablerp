<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Company;
use App\Domains\Tenancy\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            // El closure recibe los atributos ya resueltos, así que la sucursal
            // se crea en la misma empresa que la bodega y nunca en otra.
            'branch_id' => fn (array $attributes) => Branch::factory()
                ->forCompany($attributes['company_id'])
                ->create()
                ->id,
            'code' => 'BOD'.fake()->unique()->numerify('##'),
            'name' => 'Bodega '.fake()->word(),
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
        ]);
    }

    public function forCompany(Company|int $company): static
    {
        $companyId = $company instanceof Company ? $company->id : $company;

        return $this->state(fn (array $attributes) => [
            'company_id' => $companyId,
            'branch_id' => Branch::factory()->forCompany($companyId)->create()->id,
        ]);
    }
}
