<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Billing\Services\QuotaService;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Identity\Exceptions\IdentityException;
use App\Domains\Tenancy\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Alta y mantenimiento de la gente que entra al sistema.
 *
 * ## Un usuario pertenece al tenant; su acceso, a la empresa
 *
 * La cuenta de correo es única en todo el sistema, y una misma persona puede
 * trabajar en varias empresas del mismo tenant con roles distintos —Contador en
 * una, Auditor en otra—. Por eso el alta hace dos cosas separadas: crear al
 * usuario si no existe, y darle acceso a **esta** empresa con **este** rol.
 *
 * ## Lo que nunca se hace desde aquí
 *
 * **No se borra un usuario.** Sus documentos lo referencian —quién emitió, quién
 * anuló, quién cerró la caja— y borrarlo dejaría la bitácora hablando de alguien
 * que no existe. Lo que se hace es desactivarlo o quitarle el acceso a la
 * empresa, que es lo que la gente quiere decir cuando dice «bórralo».
 *
 * **No se ve ni se cambia una contraseña ajena.** Se puede generar una temporal,
 * que el sistema muestra una sola vez a quien la generó. Poder leer la
 * contraseña de otro no es una función, es un problema.
 */
final class CompanyUserService
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly RoleProvisioner $roles,
        private readonly QuotaService $quotas,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Gente con acceso a la empresa activa, con su rol resuelto.
     *
     * @return Collection<int, User>
     */
    public function forCurrentCompany(): Collection
    {
        $company = $this->context->companyOrFail();

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->id);

        try {
            return $company->users()
                ->with('roles')
                ->orderBy('name')
                ->get();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    /**
     * Da de alta a alguien en la empresa activa.
     *
     * Si el correo ya existe en el tenant, no crea otro usuario: le da acceso al
     * que ya está. Es lo que espera quien contrata a alguien que ya trabajaba en
     * la empresa hermana.
     *
     * @param  array<string, mixed>  $data
     * @return array{user: User, password: string|null}
     */
    public function invite(array $data): array
    {
        $company = $this->context->companyOrFail();
        $email = mb_strtolower(trim((string) $data['email']));

        return DB::transaction(function () use ($company, $data, $email): array {
            $existing = User::query()->where('email', $email)->first();

            if ($existing !== null && $existing->tenant_id !== $company->tenant_id) {
                throw IdentityException::emailBelongsToAnotherTenant($email);
            }

            if ($existing !== null && $existing->companies()->whereKey($company->id)->exists()) {
                throw IdentityException::alreadyHasAccess($existing, $company);
            }

            // El límite de usuarios es del plan y se comprueba al crear, no al
            // usar: bajar de plan no le quita el acceso a quien ya lo tenía.
            $this->quotas->guardUser($company->tenant);

            $password = null;

            if ($existing === null) {
                $password = $this->temporaryPassword();

                $user = new User;
                $user->forceFill([
                    'tenant_id' => $company->tenant_id,
                    'name' => trim((string) $data['name']),
                    'email' => $email,
                    'password' => Hash::make($password),
                    'is_active' => true,
                    'default_company_id' => $company->id,
                ])->save();
            } else {
                $user = $existing;
            }

            $user->companies()->syncWithoutDetaching([
                $company->id => ['branch_id' => $data['branch_id'] ?? null],
            ]);

            $this->roles->assign($user, $company, (string) $data['role']);

            $this->audit->log('granted_access', $user, newValues: [
                'company' => $company->legal_name,
                'role' => $data['role'],
            ], module: 'identity');

            return ['user' => $user->refresh(), 'password' => $password];
        });
    }

    /**
     * Cambia nombre, sucursal y rol dentro de la empresa activa.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): User
    {
        $company = $this->context->companyOrFail();

        $this->guardBelongs($user, $company);

        return DB::transaction(function () use ($user, $company, $data): User {
            $previousRole = $this->roleNameFor($user, $company);

            if (isset($data['name'])) {
                $user->forceFill(['name' => trim((string) $data['name'])])->save();
            }

            $user->companies()->updateExistingPivot($company->id, [
                'branch_id' => $data['branch_id'] ?? null,
            ]);

            if (isset($data['role']) && $data['role'] !== $previousRole) {
                $this->guardNotLastAdmin($user, $company, (string) $data['role']);
                $this->replaceRole($user, $company, (string) $data['role']);

                $this->audit->log('role_changed', $user,
                    oldValues: ['role' => $previousRole],
                    newValues: ['role' => $data['role']],
                    module: 'identity');
            }

            return $user->refresh();
        });
    }

    /**
     * Activa o desactiva al usuario en todo el sistema.
     *
     * Es global y no por empresa a propósito: cuando alguien deja de trabajar,
     * deja de entrar, no «deja de entrar a una de las tres empresas». Corta
     * también sus tokens de API, porque el middleware comprueba que siga activo.
     */
    public function setActive(User $user, bool $active): User
    {
        $company = $this->context->companyOrFail();

        $this->guardBelongs($user, $company);
        $this->guardNotSelf($user, 'desactivarte a vos mismo');

        if (! $active) {
            $this->guardNotLastAdmin($user, $company);
        }

        $user->forceFill(['is_active' => $active])->save();

        $this->audit->log($active ? 'activated' : 'deactivated', $user, module: 'identity');

        return $user->refresh();
    }

    /**
     * Le quita el acceso a esta empresa, sin tocar las demás ni borrarlo.
     */
    public function revokeAccess(User $user): void
    {
        $company = $this->context->companyOrFail();

        $this->guardBelongs($user, $company);
        $this->guardNotSelf($user, 'quitarte el acceso a vos mismo');
        $this->guardNotLastAdmin($user, $company);

        DB::transaction(function () use ($user, $company): void {
            $this->removeRoles($user, $company);
            $user->companies()->detach($company->id);

            if ($user->default_company_id === $company->id) {
                $user->forceFill([
                    'default_company_id' => $user->companies()->value('companies.id'),
                ])->save();
            }

            $this->audit->log('access_revoked', $user, oldValues: [
                'company' => $company->legal_name,
            ], module: 'identity');
        });
    }

    /**
     * Genera una contraseña temporal y la devuelve una sola vez.
     *
     * Lo que se guarda es el hash. Quien la genera se la pasa a la persona por
     * el medio que quiera; el sistema no puede volver a mostrarla, que es
     * exactamente lo que se espera de una contraseña.
     */
    public function resetPassword(User $user): string
    {
        $company = $this->context->companyOrFail();

        $this->guardBelongs($user, $company);

        $password = $this->temporaryPassword();

        $user->forceFill(['password' => Hash::make($password)])->save();

        $this->audit->log('password_reset', $user, module: 'identity');

        return $password;
    }

    /**
     * Rol del usuario dentro de la empresa dada.
     */
    public function roleNameFor(User $user, Company $company): ?string
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->id);

        try {
            // Cualificado: `roles` y `model_has_roles` tienen las dos una columna
            // `company_id`, y sin el prefijo MySQL no sabe cuál se le pide.
            return $user->roles()->where('roles.company_id', $company->id)->value('name');
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Guardas
    |--------------------------------------------------------------------------
    */

    private function guardBelongs(User $user, Company $company): void
    {
        if (! $user->companies()->whereKey($company->id)->exists()) {
            throw IdentityException::notInCompany($user, $company);
        }
    }

    private function guardNotSelf(User $user, string $action): void
    {
        if (Auth::id() === $user->id) {
            throw IdentityException::cannotActOnSelf($action);
        }
    }

    /**
     * Una empresa no puede quedarse sin administrador.
     *
     * Es la guarda que evita el error irreversible: quitarle el rol al único
     * administrador deja a la empresa sin nadie que pueda volver a dárselo, y
     * la única salida es la consola del servidor.
     */
    private function guardNotLastAdmin(User $user, Company $company, ?string $newRole = null): void
    {
        if ($newRole === PermissionCatalog::ADMIN) {
            return;
        }

        if ($this->roleNameFor($user, $company) !== PermissionCatalog::ADMIN) {
            return;
        }

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->id);

        try {
            $admins = $company->users()
                ->where('users.is_active', true)
                ->whereHas('roles', fn ($q) => $q
                    ->where('name', PermissionCatalog::ADMIN)
                    ->where('roles.company_id', $company->id))
                ->count();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }

        if ($admins <= 1) {
            throw IdentityException::lastAdministrator($company);
        }
    }

    private function replaceRole(User $user, Company $company, string $role): void
    {
        $this->removeRoles($user, $company);
        $this->roles->assign($user, $company, $role);
    }

    private function removeRoles(User $user, Company $company): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->id);

        try {
            foreach ($user->roles()->where('roles.company_id', $company->id)->get() as $role) {
                $user->removeRole($role);
            }
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $registrar->forgetCachedPermissions();
        }
    }

    /**
     * Contraseña temporal legible por teléfono: sin caracteres que se confundan
     * al dictarla, y lo bastante larga para que no importe.
     */
    private function temporaryPassword(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
        $password = '';

        for ($i = 0; $i < 12; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }
}
