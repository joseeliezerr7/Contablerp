<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Catalog\Models\PriceList;
use App\Domains\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceList>
 */
class PriceListFactory extends Factory
{
    protected $model = PriceList::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => 'LP'.fake()->unique()->numerify('###'),
            'name' => 'Lista '.fake()->word(),
            'is_default' => false,
            'is_active' => true,
        ];
    }
}
