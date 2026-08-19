<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Accounting\Enums\PeriodStatus;
use App\Domains\Accounting\Models\AccountingPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountingPeriod>
 */
class AccountingPeriodFactory extends Factory
{
    protected $model = AccountingPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::now()->startOfMonth();

        return [
            'fiscal_year_id' => FiscalYear::factory(),
            'company_id' => fn (array $attributes) => FiscalYear::withoutGlobalScopes()
                ->findOrFail($attributes['fiscal_year_id'])->company_id,
            'number' => (int) $start->format('n'),
            'name' => ucfirst($start->locale('es')->monthName).' '.$start->format('Y'),
            'starts_on' => $start->toDateString(),
            'ends_on' => $start->endOfMonth()->toDateString(),
            'status' => PeriodStatus::Open,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PeriodStatus::Closed,
            'closed_at' => now(),
        ]);
    }

    public function locked(): static
    {
        return $this->state(fn (array $attributes) => ['status' => PeriodStatus::Locked]);
    }
}
