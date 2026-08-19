<?php

declare(strict_types=1);

namespace App\Domains\Receivables\Models;

use App\Domains\Partners\Models\Customer;
use App\Domains\Receivables\Enums\ReceivableStatus;
use App\Domains\Sales\Models\Sale;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cuenta por cobrar.
 *
 * `balance` es una columna generada en la base de datos, no un campo que se
 * actualice a mano; por eso no está en $fillable y siempre concuerda con lo
 * cobrado.
 *
 * @property string $original_amount
 * @property string $paid_amount
 * @property string $balance
 * @property ReceivableStatus $status
 */
#[Fillable(['customer_id', 'sale_id', 'document_number', 'date', 'due_date', 'original_amount'])]
class Receivable extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'due_date' => 'date',
            'original_amount' => 'decimal:4',
            'paid_amount' => 'decimal:4',
            'credited_amount' => 'decimal:4',
            'balance' => 'decimal:4',
            'status' => ReceivableStatus::class,
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return HasMany<ReceiptApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(ReceiptApplication::class);
    }

    public function originalAmount(): Money
    {
        return Money::of($this->original_amount);
    }

    public function paidAmount(): Money
    {
        return Money::of($this->paid_amount);
    }

    /**
     * Rebajado por notas de crédito. No es dinero cobrado.
     */
    public function creditedAmount(): Money
    {
        return Money::of($this->credited_amount);
    }

    public function balanceAmount(): Money
    {
        return Money::of($this->balance);
    }

    public function isOutstanding(): bool
    {
        return $this->status === ReceivableStatus::Open;
    }

    public function isOverdue(?\DateTimeInterface $asOf = null): bool
    {
        $asOf ??= now();

        return $this->isOutstanding() && $this->due_date->lt($asOf);
    }

    /**
     * Días de atraso a una fecha. Cero si aún no vence.
     */
    public function daysOverdue(?\DateTimeInterface $asOf = null): int
    {
        $asOf = $asOf === null ? now()->startOfDay() : CarbonImmutable::parse($asOf)->startOfDay();

        return $this->due_date->gte($asOf) ? 0 : (int) $this->due_date->diffInDays($asOf);
    }

    /** @param  Builder<self>  $query */
    public function scopeOutstanding(Builder $query): void
    {
        $query->where('status', ReceivableStatus::Open)->where('balance', '>', 0);
    }

    /** @param  Builder<self>  $query */
    public function scopeOverdue(Builder $query, ?\DateTimeInterface $asOf = null): void
    {
        $query->outstanding()->where('due_date', '<', ($asOf ?? now())->format('Y-m-d'));
    }
}
