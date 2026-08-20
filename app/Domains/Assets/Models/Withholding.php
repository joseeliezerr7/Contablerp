<?php

declare(strict_types=1);

namespace App\Domains\Assets\Models;

use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una retención practicada.
 *
 * Guarda la tasa con la que se practicó y no solo el tipo: cuando el fisco
 * cambie el porcentaje, los documentos de ayer deben seguir mostrando el de
 * ayer. Es el mismo criterio que ya se aplica a los impuestos.
 *
 * @property string $rate
 * @property string $amount
 */
class Withholding extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'base_amount' => 'decimal:4',
            'rate' => 'decimal:6',
            'amount' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<WithholdingType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(WithholdingType::class, 'withholding_type_id');
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    public function baseAmount(): Money
    {
        return Money::of($this->base_amount);
    }
}
