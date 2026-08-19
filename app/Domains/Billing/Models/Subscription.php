<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Suscripción de un tenant.
 *
 * Los límites y el precio están copiados del plan a propósito: la suscripción
 * es el contrato, y una cuenta puede tener condiciones negociadas. Cambiar el
 * plan no le cambia las condiciones a quien ya estaba.
 *
 * @property SubscriptionStatus $status
 * @property string $price
 */
class Subscription extends Model
{
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'price' => 'decimal:4',
            'max_companies' => 'integer',
            'max_users' => 'integer',
            'max_branches' => 'integer',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'date',
            'current_period_end' => 'date',
            'cancelled_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<SubscriptionInvoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    public function priceAmount(): Money
    {
        return Money::of($this->price);
    }

    /**
     * Precio mensualizado, para el ingreso recurrente.
     */
    public function monthlyPrice(): Money
    {
        return $this->interval === 'yearly'
            ? $this->priceAmount()->dividedBy(12)
            : $this->priceAmount();
    }

    public function allowsAccess(): bool
    {
        return $this->status->allowsAccess();
    }

    public function isTrialing(): bool
    {
        return $this->status === SubscriptionStatus::Trialing;
    }

    public function isCancelled(): bool
    {
        return $this->status === SubscriptionStatus::Cancelled;
    }

    /**
     * Días que le quedan de prueba. Negativo si ya venció.
     */
    public function trialDaysLeft(?DateTimeInterface $asOf = null): ?int
    {
        if ($this->trial_ends_at === null) {
            return null;
        }

        $now = CarbonImmutable::parse($asOf ?? now())->startOfDay();

        return (int) $now->diffInDays(CarbonImmutable::parse($this->trial_ends_at)->startOfDay(), false);
    }

    public function trialHasExpired(?DateTimeInterface $asOf = null): bool
    {
        return $this->isTrialing()
            && $this->trial_ends_at !== null
            && CarbonImmutable::parse($this->trial_ends_at)->isBefore($asOf ?? now());
    }

    /** @param  Builder<self>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', '!=', SubscriptionStatus::Cancelled);
    }

    /** @param  Builder<self>  $query */
    public function scopeBillable(Builder $query): void
    {
        $query->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue]);
    }
}
