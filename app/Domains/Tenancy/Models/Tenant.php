<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use App\Domains\Tenancy\Enums\TenantStatus;
use App\Models\User;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cuenta SaaS. Agrupa usuarios y empresas bajo una sola suscripción.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property TenantStatus $status
 */
#[UseFactory(TenantFactory::class)]
#[Fillable(['name', 'slug', 'status', 'trial_ends_at'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'trial_ends_at' => 'datetime',
        ];
    }

    /** @return HasMany<Company, $this> */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function allowsAccess(): bool
    {
        return $this->status->allowsAccess();
    }
}
