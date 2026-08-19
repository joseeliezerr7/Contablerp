<?php

declare(strict_types=1);

namespace App\Domains\Assets\Models;

use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que le tocó a un activo en una corrida.
 *
 * Guarda el acumulado y el valor en libros **después** de aplicarla, para poder
 * auditar un mes concreto sin recalcular toda la historia del activo.
 *
 * @property string $amount
 */
class DepreciationRunLine extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'accumulated_after' => 'decimal:4',
            'book_value_after' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<DepreciationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(DepreciationRun::class, 'depreciation_run_id');
    }

    /** @return BelongsTo<FixedAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    public function accumulatedAfter(): Money
    {
        return Money::of($this->accumulated_after);
    }

    public function bookValueAfter(): Money
    {
        return Money::of($this->book_value_after);
    }
}
