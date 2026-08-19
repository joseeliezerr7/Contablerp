<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Data\HonduranChartOfAccounts;
use App\Domains\Accounting\Enums\AccountNature;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\CashFlowClass;
use App\Domains\Accounting\Exceptions\InvalidAccountException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\AccountMapping;
use App\Domains\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Mantenimiento del plan de cuentas.
 *
 * Concentra las reglas de la jerarquía: nivel, ruta materializada, naturaleza
 * derivada del tipo, y la regla de que una cuenta con subcuentas deja de
 * admitir movimientos.
 */
final class ChartOfAccountsService
{
    /**
     * Siembra el catálogo base de una empresa recién creada, junto con las
     * cuentas por módulo.
     *
     * No usa consultas con scope: se ejecuta al crear la empresa, cuando la
     * empresa activa del contexto es otra (o no hay ninguna). El árbol se arma
     * en memoria a partir de los códigos.
     *
     * @param  array<int, array{code: string, name: string, type: AccountType, postable: bool, nature: ?AccountNature, cash_flow: ?CashFlowClass, system: bool}>|null  $definition
     * @param  array<string, string>|null  $mappings
     */
    public function seedFor(Company $company, ?array $definition = null, ?array $mappings = null): void
    {
        $definition ??= HonduranChartOfAccounts::definition();
        $mappings ??= HonduranChartOfAccounts::mappings();
        $cashAccounts = HonduranChartOfAccounts::cashAccounts();

        DB::transaction(function () use ($company, $definition, $mappings, $cashAccounts): void {
            /** @var array<string, Account> $byCode */
            $byCode = [];

            foreach ($definition as $row) {
                $parent = $this->resolveParentFromCode($row['code'], $byCode);

                // forceFill y no create(): `level`, `path`, `is_postable` e
                // `is_system` los calcula el sistema y quedan deliberadamente
                // fuera de $fillable para que ningún formulario pueda tocarlos.
                $account = new Account;
                $account->forceFill([
                    'company_id' => $company->id,
                    'parent_id' => $parent?->id,
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'nature' => $row['nature'] ?? $row['type']->nature(),
                    'level' => $parent === null ? 1 : $parent->level + 1,
                    'is_postable' => $row['postable'],
                    'is_system' => $row['system'],
                    'cash_flow_class' => $row['cash_flow'],
                    'is_cash_equivalent' => in_array($row['code'], $cashAccounts, strict: true),
                    'is_active' => true,
                    'path' => $parent === null ? $row['code'] : $parent->path.'/'.$row['code'],
                ])->save();

                $byCode[$row['code']] = $account;
            }

            foreach ($mappings as $key => $code) {
                if (! isset($byCode[$code])) {
                    throw InvalidAccountException::missingMapping(
                        "{$key} (la cuenta {$code} no existe en el catálogo)"
                    );
                }

                $mapping = new AccountMapping;
                $mapping->forceFill([
                    'company_id' => $company->id,
                    'key' => $key,
                    'account_id' => $byCode[$code]->id,
                ])->save();
            }
        });
    }

    /**
     * Alta interactiva de una cuenta dentro de la empresa activa.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Account
    {
        return DB::transaction(function () use ($data): Account {
            $parent = $this->findParent($data['parent_id'] ?? null);

            if ($parent !== null) {
                $this->guardParentCanHaveChildren($parent, (string) $data['code']);
            }

            $type = $data['type'] instanceof AccountType
                ? $data['type']
                : AccountType::from((string) $data['type']);

            // Una sola escritura: `path` es NOT NULL, así que no puede quedar
            // pendiente de un segundo save.
            $account = new Account;
            $account->fill([
                ...$data,
                'type' => $type,
                'nature' => $data['nature'] ?? $type->nature(),
            ]);

            $account->forceFill([
                'level' => $parent === null ? 1 : $parent->level + 1,
                'path' => $account->buildPath($parent),
            ])->save();

            // Al ganar una subcuenta, el padre pasa a ser de agrupación.
            $parent?->forceFill(['is_postable' => false])->save();

            return $account;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Account $account, array $data): Account
    {
        return DB::transaction(function () use ($account, $data): Account {
            $changingType = isset($data['type'])
                && AccountType::from((string) $data['type']) !== $account->type;
            $changingNature = isset($data['nature'])
                && (string) $data['nature'] !== $account->nature->value;

            if (($changingType || $changingNature) && $this->hasMovements($account)) {
                throw InvalidAccountException::hasMovements($account);
            }

            // El código forma parte de la ruta de toda la rama; cambiarlo obliga
            // a recalcular los descendientes.
            $codeChanged = isset($data['code']) && $data['code'] !== $account->code;

            $account->update($data);

            if ($codeChanged) {
                $account->forceFill(['path' => $account->buildPath($account->parent)])->save();
                $this->rebuildDescendantPaths($account);
            }

            return $account->refresh();
        });
    }

    public function delete(Account $account): void
    {
        if ($account->is_system) {
            throw InvalidAccountException::isSystem($account);
        }

        if ($account->children()->exists()) {
            throw InvalidAccountException::hasChildren($account);
        }

        if ($this->hasMovements($account)) {
            throw InvalidAccountException::hasMovements($account);
        }

        DB::transaction(function () use ($account): void {
            $parent = $account->parent;

            $account->delete();

            // Si era la última subcuenta, el padre vuelve a admitir movimientos.
            if ($parent !== null && ! $parent->children()->exists()) {
                $parent->forceFill(['is_postable' => true])->save();
            }
        });
    }

    public function hasMovements(Account $account): bool
    {
        return $account->lines()->exists();
    }

    /**
     * Reconstruye `path` y `level` de toda la empresa activa. Red de seguridad
     * tras una importación o una migración de datos.
     */
    public function rebuildPaths(): int
    {
        $updated = 0;

        Account::query()
            ->whereNull('parent_id')
            ->orderBy('code')
            ->each(function (Account $root) use (&$updated): void {
                $root->forceFill(['level' => 1, 'path' => $root->code])->save();
                $updated++;
                $updated += $this->rebuildDescendantPaths($root);
            });

        return $updated;
    }

    private function rebuildDescendantPaths(Account $parent): int
    {
        $updated = 0;

        foreach ($parent->children()->get() as $child) {
            $child->forceFill([
                'level' => $parent->level + 1,
                'path' => $parent->path.'/'.$child->code,
            ])->save();

            $updated++;
            $updated += $this->rebuildDescendantPaths($child);
        }

        return $updated;
    }

    private function findParent(mixed $parentId): ?Account
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        return Account::query()->findOrFail($parentId);
    }

    private function guardParentCanHaveChildren(Account $parent, string $childCode): void
    {
        if ($this->hasMovements($parent)) {
            throw InvalidAccountException::parentHasMovements($parent);
        }

        if (! str_starts_with($childCode, $parent->code)) {
            throw InvalidAccountException::codeNotUnderParent($childCode, $parent);
        }
    }

    /**
     * Deduce el padre quitando el último segmento del código ('1.1.01' → '1.1').
     *
     * @param  array<string, Account>  $byCode
     */
    private function resolveParentFromCode(string $code, array $byCode): ?Account
    {
        $separator = strrpos($code, '.');

        if ($separator === false) {
            return null;
        }

        $parentCode = substr($code, 0, $separator);

        return $byCode[$parentCode] ?? null;
    }
}
