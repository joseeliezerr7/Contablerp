<?php

declare(strict_types=1);

namespace App\Models;

use App\Domains\Identity\Policies\UserPolicy;
use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Company;
use App\Domains\Tenancy\Models\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Se queda en App\Models por convención del framework: config/auth.php, las
 * factories y varios paquetes lo resuelven por esa ruta. Los modelos de negocio
 * sí viven en su dominio.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string $email
 * @property int|null $default_company_id
 * @property int|null $default_branch_id
 * @property bool $is_active
 */
#[Fillable(['name', 'email', 'password', 'tenant_id', 'default_company_id', 'default_branch_id', 'is_active', 'is_super_admin'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
#[UsePolicy(UserPolicy::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * Los permisos viven bajo un solo guard.
     *
     * Spatie los separa por guard, y aquí hay dos —`web` para la
     * aplicación y `sanctum` para la API—. Sin esta línea, una petición
     * autenticada con token busca los permisos del guard `sanctum`, que no
     * existen, y falla con «no existe el permiso X para el guard sanctum»
     * aunque el rol lo tenga.
     *
     * Y sería lo contrario de lo que se quiere: los permisos son de la persona,
     * no de la puerta por la que entra. Un Vendedor es el mismo Vendedor tanto
     * si abre la pantalla como si su integración llama a la API.
     */
    protected string $guard_name = 'web';

    /**
     * Valores por defecto de las columnas de tenancy.
     *
     * Sin esto, un usuario recién creado que no las especifica queda sin esos
     * atributos en memoria, y el middleware que lee `$user->tenant` en la misma
     * petición falla con MissingAttributeException.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'tenant_id' => null,
        'default_company_id' => null,
        'default_branch_id' => null,
        'is_active' => true,
        'is_super_admin' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_super_admin' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Administra el servicio, no una empresa.
     *
     * Es un eje de autorización distinto de los roles: aquellos responden «qué
     * puede hacer dentro de esta empresa», y el superadministrador opera entre
     * tenants, sin pertenecer a ninguna empresa. Por eso es una bandera y no un
     * rol, y por eso sus pantallas viven fuera del middleware de empresa.
     */
    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Empresas a las que este usuario tiene acceso. Es la única fuente de
     * verdad de la pertenencia: el middleware valida contra esta relación.
     *
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->withPivot('branch_id')
            ->withTimestamps();
    }

    /** @return BelongsTo<Company, $this> */
    public function defaultCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'default_company_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    public function belongsToCompany(int $companyId): bool
    {
        return $this->companies()->whereKey($companyId)->exists();
    }

    /**
     * Empresa que debe activarse al iniciar sesión: la preferida del usuario si
     * sigue teniendo acceso, si no la primera disponible.
     */
    public function resolveDefaultCompany(): ?Company
    {
        $preferred = $this->default_company_id;

        if ($preferred !== null) {
            $company = $this->companies()->whereKey($preferred)->first();

            if ($company !== null) {
                return $company;
            }
        }

        return $this->companies()->orderBy('legal_name')->first();
    }
}
