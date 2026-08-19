<?php

declare(strict_types=1);

namespace App\Domains\Sales\Models;

use App\Domains\Accounting\Models\Account;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cobro aplicado al emitir la factura.
 *
 * @property PaymentMethod $method
 */
#[Fillable(['method', 'account_id', 'amount', 'tendered', 'change_given', 'reference'])]
class SalePayment extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'decimal:4',
            'tendered' => 'decimal:4',
            'change_given' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    public function tenderedMoney(): ?Money
    {
        return $this->tendered === null ? null : Money::of($this->tendered);
    }

    public function changeMoney(): ?Money
    {
        return $this->change_given === null ? null : Money::of($this->change_given);
    }

    public function label(): string
    {
        return $this->method->label().' '.$this->amountMoney()->format();
    }
}
