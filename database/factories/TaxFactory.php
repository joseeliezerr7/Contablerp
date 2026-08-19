<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Taxation\Models\Tax;
use App\Domains\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tax>
 */
class TaxFactory extends Factory
{
    protected $model = Tax::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => 'IMP'.fake()->unique()->numerify('###'),
            'name' => 'Impuesto de prueba',
            'rate' => '15.000000',
            'is_included' => false,
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function rate(string $rate): static
    {
        return $this->state(fn (array $attributes) => ['rate' => $rate]);
    }

    /**
     * Impuesto incluido en el precio, como en la venta al público.
     */
    public function included(): static
    {
        return $this->state(fn (array $attributes) => ['is_included' => true]);
    }
}
