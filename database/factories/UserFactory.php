<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * Da acceso al usuario a una empresa y la deja como su empresa por defecto.
     * Hereda el tenant de la empresa para mantener coherente la jerarquía.
     */
    public function forCompany(Company $company, ?Branch $branch = null): static
    {
        return $this
            ->state(fn (array $attributes) => [
                'tenant_id' => $company->tenant_id,
                'default_company_id' => $company->id,
                'default_branch_id' => $branch?->id,
            ])
            ->afterCreating(function (User $user) use ($company, $branch): void {
                $user->companies()->syncWithoutDetaching([
                    $company->id => ['branch_id' => $branch?->id],
                ]);
            });
    }
}
