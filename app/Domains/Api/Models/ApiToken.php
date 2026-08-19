<?php

declare(strict_types=1);

namespace App\Domains\Api\Models;

use App\Domains\Api\Policies\ApiTokenPolicy;
use App\Domains\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token de API, extendido con la empresa sobre la que actúa.
 *
 * Hereda de Sanctum en vez de reimplementar: el hasheo del secreto y su
 * verificación son justo el código que uno no debe escribir a mano.
 *
 * **No usa `BelongsToCompany`.** El scope global filtra por la empresa activa, y
 * cuando se resuelve un token todavía no hay ninguna activa —resolverlo es
 * precisamente lo que la establece—. El aislamiento aquí lo da el propio
 * `company_id` del token, comprobado al autenticar.
 */
#[UsePolicy(ApiTokenPolicy::class)]
class ApiToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    public function casts(): array
    {
        return [
            ...parent::casts(),
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Si el token sirve para autenticar ahora mismo.
     */
    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * Estado legible, para la pantalla.
     */
    public function statusLabel(): string
    {
        return match (true) {
            $this->isRevoked() => 'Revocado',
            $this->isExpired() => 'Vencido',
            default => 'Activo',
        };
    }

    public function statusClasses(): string
    {
        return match (true) {
            $this->isRevoked() => 'bg-red-50 text-red-700',
            $this->isExpired() => 'bg-amber-50 text-amber-700',
            default => 'bg-emerald-50 text-emerald-700',
        };
    }

    /**
     * @return array<int, string>
     */
    public function scopes(): array
    {
        return array_values(array_filter(
            $this->abilities ?? [],
            fn (string $ability) => $ability !== '*',
        ));
    }

    /** @param  Builder<self>  $query */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
