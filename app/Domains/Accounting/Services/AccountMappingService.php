<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Enums\AccountMappingKey;
use App\Domains\Accounting\Exceptions\InvalidAccountException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\AccountMapping;
use Illuminate\Support\Collection;

/**
 * Resuelve las claves de los módulos a cuentas concretas.
 *
 * Es el único punto donde el resto del sistema pregunta «¿cuál es la cuenta de
 * ventas?». Sin esto, cada servicio buscaría la cuenta por código y el sistema
 * solo funcionaría con el catálogo hondureño.
 */
final class AccountMappingService
{
    /** @var array<string, Account> Caché por petición. */
    private array $resolved = [];

    public function resolve(AccountMappingKey $key): Account
    {
        if (isset($this->resolved[$key->value])) {
            return $this->resolved[$key->value];
        }

        $mapping = AccountMapping::query()
            ->with('account')
            ->where('key', $key->value)
            ->first();

        if ($mapping === null || $mapping->account === null) {
            throw InvalidAccountException::missingMapping($key->label());
        }

        if (! $mapping->account->acceptsPostings()) {
            throw InvalidAccountException::notPostable($mapping->account);
        }

        return $this->resolved[$key->value] = $mapping->account;
    }

    public function resolveId(AccountMappingKey $key): int
    {
        return $this->resolve($key)->id;
    }

    /**
     * Reasigna una clave a otra cuenta.
     */
    public function assign(AccountMappingKey $key, Account $account): AccountMapping
    {
        if (! $account->acceptsPostings()) {
            throw InvalidAccountException::notPostable($account);
        }

        unset($this->resolved[$key->value]);

        return AccountMapping::updateOrCreate(
            ['key' => $key->value],
            ['account_id' => $account->id],
        );
    }

    /**
     * Todas las claves con la cuenta asignada, para la pantalla de
     * configuración.
     *
     * @return Collection<string, AccountMapping>
     */
    public function all(): Collection
    {
        return AccountMapping::query()->with('account')->get()->keyBy('key');
    }

    /**
     * Claves que aún no tienen cuenta. Un módulo que dependa de una de ellas
     * fallará al intentar contabilizar.
     *
     * @return array<int, AccountMappingKey>
     */
    public function missing(): array
    {
        $configured = AccountMapping::query()->pluck('key')->all();

        return array_values(array_filter(
            AccountMappingKey::cases(),
            fn (AccountMappingKey $key) => ! in_array($key->value, $configured, strict: true),
        ));
    }
}
