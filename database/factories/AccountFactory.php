<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Accounting\Enums\AccountNature;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(AccountType::cases());
        $code = $type->defaultCodePrefix().'.9.'.fake()->unique()->numerify('##');

        return [
            'company_id' => Company::factory(),
            'parent_id' => null,
            'code' => $code,
            'name' => 'Cuenta '.fake()->words(2, true),
            'type' => $type,
            'nature' => $type->nature(),
            'level' => 1,
            'is_postable' => true,
            'is_system' => false,
            'is_active' => true,
            'path' => $code,
        ];
    }

    public function ofType(AccountType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
            'nature' => $type->nature(),
        ]);
    }

    public function withNature(AccountNature $nature): static
    {
        return $this->state(fn (array $attributes) => ['nature' => $nature]);
    }

    /**
     * Cuenta de agrupación: no admite movimientos.
     */
    public function group(): static
    {
        return $this->state(fn (array $attributes) => ['is_postable' => false]);
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
