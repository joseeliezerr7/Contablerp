<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Policies\AuditLogPolicy;
use App\Domains\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Registro de auditoría. Inmutable por diseño: solo `created_at`.
 *
 * No usa el trait BelongsToCompany a propósito. Un auditor debe poder consultar
 * eventos cuya empresa ya fue eliminada (`company_id` queda en NULL), y el
 * scope global los ocultaría. El filtrado por empresa se aplica de forma
 * explícita en las consultas.
 *
 * @property string $event
 * @property string $auditable_type
 * @property int $auditable_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 */
#[Fillable([
    'company_id', 'user_id', 'event', 'auditable_type', 'auditable_id',
    'module', 'old_values', 'new_values', 'reason', 'ip_address', 'user_agent',
])]
#[UsePolicy(AuditLogPolicy::class)]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeForCompany(Builder $query, int $companyId): void
    {
        $query->where('company_id', $companyId);
    }

    /** @param  Builder<self>  $query */
    public function scopeForModel(Builder $query, Model $model): void
    {
        $query->where('auditable_type', $model::class)
            ->where('auditable_id', $model->getKey());
    }
}
