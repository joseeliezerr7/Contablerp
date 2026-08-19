<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Factura del servicio: lo que el proveedor le cobra al cliente por usar el
 * sistema.
 *
 * **No genera partida contable en ningún libro.** Es ingreso del proveedor, y
 * el proveedor no lleva su contabilidad dentro de esta aplicación. Registrarla
 * en el libro del cliente le metería el precio del software como un gasto que
 * él nunca capturó.
 *
 * @property InvoiceStatus $status
 * @property string $amount
 */
class SubscriptionInvoice extends Model
{
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'due_on' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'paid_on' => 'date',
            'amount' => 'decimal:4',
            'status' => InvoiceStatus::class,
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    public function isPending(): bool
    {
        return $this->status === InvoiceStatus::Pending;
    }

    public function isOverdue(?DateTimeInterface $asOf = null): bool
    {
        return $this->isPending()
            && CarbonImmutable::parse($this->due_on)->isBefore($asOf ?? now());
    }

    public function label(): string
    {
        return 'Factura '.$this->number;
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', InvoiceStatus::Pending);
    }
}
