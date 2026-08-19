<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Enums\JournalEntryType;
use App\Domains\Accounting\Models\AccountingPeriod;
use App\Domains\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Crea la partida "en crudo", sin pasar por el motor contable. Sirve para
 * probar consultas y permisos; para probar el flujo real hay que usar
 * AccountingEngine, que es quien aplica las validaciones.
 *
 * @extends Factory<JournalEntry>
 */
class JournalEntryFactory extends Factory
{
    protected $model = JournalEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'accounting_period_id' => AccountingPeriod::factory(),
            'company_id' => fn (array $attributes) => AccountingPeriod::withoutGlobalScopes()
                ->findOrFail($attributes['accounting_period_id'])->company_id,
            'number' => null,
            'date' => now()->toDateString(),
            'type' => JournalEntryType::Standard,
            'concept' => fake()->sentence(4),
            'currency_code' => 'HNL',
            'exchange_rate' => 1,
            'total_debit' => 0,
            'total_credit' => 0,
            'status' => JournalEntryStatus::Draft,
        ];
    }

    public function posted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => JournalEntryStatus::Posted,
            'number' => 'PD-'.fake()->unique()->numerify('######'),
            'posted_at' => now(),
        ]);
    }
}
