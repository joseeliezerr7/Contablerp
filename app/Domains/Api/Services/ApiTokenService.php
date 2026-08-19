<?php

declare(strict_types=1);

namespace App\Domains\Api\Services;

use App\Domains\Api\Data\ApiScope;
use App\Domains\Api\Models\ApiToken;
use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Tenancy\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Emisión y baja de tokens de API.
 *
 * ## El secreto se muestra una sola vez
 *
 * Lo que se guarda es el hash. El texto plano se devuelve al emitir y no se
 * puede recuperar después, que es exactamente lo que uno quiere de un secreto:
 * si el sistema pudiera enseñártelo mañana, también podría enseñárselo a quien
 * entre a la base de datos.
 *
 * ## Los tokens no se borran, se revocan
 *
 * Borrar la fila deja la bitácora hablando de un token que no existe. Revocarlo
 * lo deja inservible y visible, que es lo que sirve cuando hay que reconstruir
 * qué pasó.
 */
final class ApiTokenService
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Emite un token para el usuario sobre la empresa activa.
     *
     * @param  array<int, string>  $scopes
     * @return array{token: ApiToken, plain: string}
     */
    public function issue(
        User $user,
        string $name,
        array $scopes,
        DateTimeInterface|string|null $expiresAt = null,
    ): array {
        $company = $this->context->companyOrFail();

        $this->guardBelongsTo($user, $company);
        $this->guardScopes($scopes);

        return DB::transaction(function () use ($user, $company, $name, $scopes, $expiresAt): array {
            $expires = $expiresAt === null ? null : CarbonImmutable::parse($expiresAt);

            $result = $user->createToken($name, $scopes, $expires);

            /** @var ApiToken $token */
            $token = ApiToken::query()->findOrFail($result->accessToken->getKey());
            $token->forceFill(['company_id' => $company->id])->save();

            $this->audit->log('issued', $token, newValues: [
                'name' => $name,
                'scopes' => $scopes,
                'expires_at' => $expires?->toDateString(),
            ], module: 'api');

            return ['token' => $token->refresh(), 'plain' => $result->plainTextToken];
        });
    }

    /**
     * Deja el token inservible sin borrarlo.
     */
    public function revoke(ApiToken $token, ?string $reason = null): ApiToken
    {
        if ($token->company_id !== $this->context->id()) {
            throw new RuntimeException('El token pertenece a otra empresa.');
        }

        $token->forceFill(['revoked_at' => now()])->save();

        $this->audit->log('revoked', $token, newValues: [
            'name' => $token->name,
        ], reason: $reason, module: 'api');

        return $token->refresh();
    }

    /**
     * Tokens de la empresa activa.
     *
     * @return Collection<int, ApiToken>
     */
    public function forCurrentCompany(): Collection
    {
        // `tokenable` es el dueño y la pantalla lo muestra en cada fila: sin
        // cargarlo aquí son N consultas, y con el modo estricto encendido ni
        // siquiera llega a ser lento, revienta.
        return ApiToken::query()
            ->with(['company', 'tokenable'])
            ->where('company_id', $this->context->idOrFail())
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Quien emite un token solo puede emitirlo sobre una empresa suya, y solo
     * para sí mismo o para alguien que también pertenezca a ella.
     */
    private function guardBelongsTo(User $user, Company $company): void
    {
        if (! $user->companies()->whereKey($company->id)->exists()) {
            throw new RuntimeException(sprintf(
                'El usuario %s no tiene acceso a %s.',
                $user->name,
                $company->legal_name,
            ));
        }

        $issuer = Auth::user();

        if ($issuer !== null && ! $issuer->companies()->whereKey($company->id)->exists()) {
            throw new RuntimeException('No podés emitir tokens sobre una empresa que no es tuya.');
        }
    }

    /**
     * @param  array<int, string>  $scopes
     */
    private function guardScopes(array $scopes): void
    {
        if ($scopes === []) {
            throw new RuntimeException('Un token sin alcances no sirve para nada: elegí al menos uno.');
        }

        $unknown = array_diff($scopes, ApiScope::values());

        if ($unknown !== []) {
            throw new RuntimeException('Alcance desconocido: '.implode(', ', $unknown));
        }
    }
}
