<?php

declare(strict_types=1);

namespace App\Domains\Treasury\Services;

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\Account;
use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Treasury\Exceptions\TreasuryException;
use App\Domains\Treasury\Models\BankAccount;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Alta y consulta de cuentas bancarias.
 *
 * El saldo **siempre** se lee del libro. Esta clase no guarda ni cachea
 * importes: preguntar al libro cuesta una consulta y no puede desincronizarse,
 * que es lo único que importa cuando el número se va a comparar contra un
 * extracto bancario.
 */
final class BankAccountService
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): BankAccount
    {
        return DB::transaction(function () use ($data): BankAccount {
            $account = Account::query()->findOrFail($data['account_id']);

            $this->guardUsableAccount($account);

            $bankAccount = new BankAccount;
            $bankAccount->forceFill([
                ...$this->attributes($data),
                'company_id' => $this->context->idOrFail(),
            ])->save();

            $this->audit->log('created', $bankAccount, newValues: [
                'bank_name' => $bankAccount->bank_name,
                'number' => $bankAccount->number,
            ], module: 'treasury');

            return $bankAccount->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BankAccount $bankAccount, array $data): BankAccount
    {
        return DB::transaction(function () use ($bankAccount, $data): BankAccount {
            if ((int) $data['account_id'] !== (int) $bankAccount->account_id) {
                $account = Account::query()->findOrFail($data['account_id']);
                $this->guardUsableAccount($account, $bankAccount);
            }

            $bankAccount->forceFill($this->attributes($data))->save();

            return $bankAccount->refresh();
        });
    }

    public function delete(BankAccount $bankAccount): void
    {
        DB::transaction(function () use ($bankAccount): void {
            $bankAccount->delete();
        });
    }

    /**
     * Saldo en libros de la cuenta a una fecha, según las partidas
     * contabilizadas. Una cuenta de banco es de naturaleza deudora: debe menos
     * haber.
     */
    public function bookBalance(BankAccount $bankAccount, DateTimeInterface|string|null $asOf = null): Money
    {
        $date = CarbonImmutable::parse($asOf ?? now())->toDateString();

        $row = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->context->idOrFail())
            ->where('l.account_id', $bankAccount->account_id)
            ->where('e.status', JournalEntryStatus::Posted->value)
            ->where('e.date', '<=', $date)
            ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
            ->first();

        return Money::of((string) $row->debit)->minus(Money::of((string) $row->credit));
    }

    /**
     * Toma el siguiente número de cheque y avanza el correlativo.
     *
     * Con bloqueo de fila, por la misma razón que los correlativos de
     * documentos: dos pagos simultáneos no pueden girar el mismo cheque.
     */
    public function nextCheckNumber(BankAccount $bankAccount): string
    {
        if (DB::transactionLevel() === 0) {
            throw new TreasuryException(
                'El número de cheque debe tomarse dentro de la transacción del pago que lo gira.'
            );
        }

        $locked = BankAccount::query()->whereKey($bankAccount->id)->lockForUpdate()->firstOrFail();

        if (! $locked->issuesChecks()) {
            throw TreasuryException::doesNotIssueChecks($locked);
        }

        $number = (int) $locked->next_check_number;

        $locked->forceFill(['next_check_number' => $number + 1])->save();

        return (string) $number;
    }

    /**
     * Una cuenta de tesorería tiene que ser una cuenta de efectivo del plan:
     * si no lo fuera, no aparecería en el estado de flujo de efectivo y el
     * saldo que se concilia no sería el que ve el contador.
     */
    private function guardUsableAccount(Account $account, ?BankAccount $ignore = null): void
    {
        if (! $account->is_cash_equivalent) {
            throw TreasuryException::accountNotCash($account);
        }

        $taken = BankAccount::query()
            ->where('account_id', $account->id)
            ->when($ignore !== null, fn ($q) => $q->whereKeyNot($ignore->id))
            ->exists();

        if ($taken) {
            throw TreasuryException::accountAlreadyLinked($account);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $checks = $data['next_check_number'] ?? null;

        return [
            'account_id' => $data['account_id'],
            'bank_name' => trim((string) $data['bank_name']),
            'number' => trim((string) $data['number']),
            'alias' => $data['alias'] ?? null,
            'type' => $data['type'] ?? 'checking',
            'currency_code' => $data['currency_code'] ?? 'HNL',
            'next_check_number' => $checks === '' || $checks === null ? null : (int) $checks,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'notes' => $data['notes'] ?? null,
        ];
    }
}
