<?php

declare(strict_types=1);

namespace App\Domains\Api\Policies;

use App\Domains\Api\Models\ApiToken;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

/**
 * `ApiToken` no lleva `BelongsToCompany` —el scope global no puede correr
 * mientras se resuelve un token—, así que la comprobación de empresa se hace
 * aquí a mano contra `company_id`.
 */
class ApiTokenPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'api.tokens.view');
    }

    public function view(User $user, ApiToken $token): bool
    {
        return $this->allows($user, 'api.tokens.view') && $this->sameCompany($token);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'api.tokens.manage');
    }

    /**
     * Un token no se edita: se revoca y se emite otro. Cambiarle los alcances a
     * uno ya repartido es cambiar en silencio lo que puede hacer una llave que
     * ya está en manos de alguien.
     */
    public function revoke(User $user, ApiToken $token): bool
    {
        return $this->allows($user, 'api.tokens.manage')
            && $this->sameCompany($token)
            && ! $token->isRevoked();
    }

    private function sameCompany(ApiToken $token): bool
    {
        return $this->context->has() && $token->company_id === $this->context->id();
    }
}
