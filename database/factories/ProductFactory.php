<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Catalog\Models\PriceList;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductPrice;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => 'PRD'.fake()->unique()->numerify('####'),
            'name' => ucfirst(fake()->words(2, true)),
            'type' => 'product',
            'cost' => '0',
            'track_inventory' => false,
            'is_active' => true,
        ];
    }

    public function service(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'service',
            'track_inventory' => false,
        ]);
    }

    /**
     * Producto que lleva control de existencias.
     */
    public function tracked(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'product',
            'track_inventory' => true,
        ]);
    }

    public function withTax(Tax $tax): static
    {
        return $this->state(fn (array $attributes) => ['tax_id' => $tax->id]);
    }

    public function withCost(string $cost): static
    {
        return $this->state(fn (array $attributes) => ['cost' => $cost]);
    }

    public function forCompany(Company|int $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company instanceof Company ? $company->id : $company,
        ]);
    }

    /**
     * Precio en una lista concreta.
     */
    public function pricedAt(string $price, ?PriceList $list = null): static
    {
        return $this->afterCreating(function (Product $product) use ($price, $list): void {
            $list ??= PriceList::default();

            if ($list === null) {
                return;
            }

            $productPrice = new ProductPrice;
            $productPrice->forceFill([
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'price_list_id' => $list->id,
                'price' => $price,
            ])->save();
        });
    }
}
