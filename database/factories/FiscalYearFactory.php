<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalYear>
 */
class FiscalYearFactory extends Factory
{
    protected $model = FiscalYear::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = (int) now()->format('Y');
        $starts = CarbonImmutable::create($year, 1, 1);

        return [
            'company_id' => Company::factory(),
            'name' => (string) $year,
            'starts_on' => $starts->toDateString(),
            'ends_on' => $starts->endOfYear()->toDateString(),
            'status' => FiscalYearStatus::Open,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => FiscalYearStatus::Closed]);
    }
}
