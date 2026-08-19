<?php

declare(strict_types=1);

namespace App\Domains\Receivables\Models;

use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuánto de un recibo se aplicó a cada factura.
 *
 * @property string $amount
 */
#[Fillable(['receipt_id', 'receivable_id', 'amount'])]
class ReceiptApplication extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    /** @return BelongsTo<Receipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    /** @return BelongsTo<Receivable, $this> */
    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }
}
