<?php

declare(strict_types=1);

namespace App\Domains\Payables\Models;

use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['payment_id', 'payable_id', 'amount'])]
class PaymentApplication extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Payable, $this> */
    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }
}
