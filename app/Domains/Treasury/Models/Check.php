<?php

declare(strict_types=1);

namespace App\Domains\Treasury\Models;

use App\Domains\Treasury\Enums\CheckStatus;
use App\Domains\Treasury\Policies\CheckPolicy;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $number
 * @property string $amount
 * @property CheckStatus $status
 */
#[UsePolicy(CheckPolicy::class)]
class Check extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'delivered_on' => 'date',
            'cleared_on' => 'date',
            'amount' => 'decimal:4',
            'status' => CheckStatus::class,
        ];
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    public function isOutstanding(): bool
    {
        return $this->status->isOutstanding();
    }

    public function isVoided(): bool
    {
        return $this->status === CheckStatus::Voided;
    }

    public function isCleared(): bool
    {
        return $this->status === CheckStatus::Cleared;
    }

    public function label(): string
    {
        return 'Cheque '.$this->number;
    }

    /**
     * Cheques que el banco todavía no ha pagado a una fecha dada.
     *
     * Un cheque cobrado *después* de la fecha de corte seguía pendiente en esa
     * fecha, y por eso no basta con mirar el estado actual.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOutstandingAt(Builder $query, string $date): void
    {
        $query->where('date', '<=', $date)
            ->whereIn('status', [CheckStatus::Issued, CheckStatus::Delivered, CheckStatus::Cleared])
            ->where(fn (Builder $q) => $q->whereNull('cleared_on')->orWhere('cleared_on', '>', $date));
    }
}
