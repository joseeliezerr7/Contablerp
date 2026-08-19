<?php

declare(strict_types=1);

namespace App\Domains\Treasury\Services;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Treasury\Enums\CheckStatus;
use App\Domains\Treasury\Exceptions\TreasuryException;
use App\Domains\Treasury\Models\BankAccount;
use App\Domains\Treasury\Models\Check;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cheques girados.
 *
 * Emitir un cheque **no genera partida contable**: el asiento lo hace el
 * documento que lo origina —el pago al proveedor—, y duplicarlo aquí
 * contabilizaría la salida dos veces. Marcarlo entregado o cobrado tampoco
 * mueve el libro: el dinero salió cuando se registró el pago; que el banco lo
 * cobre tres días después es información para la conciliación, no un hecho
 * contable nuevo.
 *
 * Esa es justamente la razón de ser de la conciliación bancaria: el libro y el
 * banco van a destiempo, y hay que poder explicar la diferencia.
 */
final class CheckService
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly BankAccountService $bankAccounts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Gira un cheque tomando el siguiente número de la chequera.
     *
     * Se llama desde la transacción del pago que lo origina.
     */
    public function issue(
        BankAccount $bankAccount,
        Money $amount,
        string $payee,
        DateTimeInterface|string $date,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $number = null,
    ): Check {
        if ($amount->isNegative()) {
            throw TreasuryException::negativeAmount();
        }

        $number ??= $this->bankAccounts->nextCheckNumber($bankAccount);

        $check = new Check;
        $check->forceFill([
            'company_id' => $this->context->idOrFail(),
            'bank_account_id' => $bankAccount->id,
            'number' => $number,
            'date' => CarbonImmutable::parse($date)->toDateString(),
            'payee' => $payee,
            'amount' => $amount->toString(),
            'status' => CheckStatus::Issued,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_by' => Auth::id(),
        ])->save();

        return $check->refresh();
    }

    public function markDelivered(Check $check, DateTimeInterface|string|null $on = null): Check
    {
        $this->guardLive($check);

        $check->forceFill([
            'status' => CheckStatus::Delivered,
            'delivered_on' => CarbonImmutable::parse($on ?? now())->toDateString(),
        ])->save();

        return $check->refresh();
    }

    /**
     * Marca que el banco pagó el cheque. A partir de aquí deja de ser un cheque
     * pendiente en la conciliación.
     */
    public function markCleared(Check $check, DateTimeInterface|string|null $on = null): Check
    {
        $this->guardLive($check);

        if ($check->isCleared()) {
            throw TreasuryException::checkAlreadyCleared($check);
        }

        $date = CarbonImmutable::parse($on ?? now())->startOfDay();

        if ($date->lt(CarbonImmutable::parse($check->date)->startOfDay())) {
            throw TreasuryException::clearedBeforeIssued($check);
        }

        $check->forceFill([
            'status' => CheckStatus::Cleared,
            'cleared_on' => $date->toDateString(),
        ])->save();

        $this->audit->log('cleared', $check, newValues: [
            'cleared_on' => $date->toDateString(),
        ], module: 'treasury');

        return $check->refresh();
    }

    /**
     * Anula el cheque como documento.
     *
     * No revierte nada en el libro: si el pago que lo originó también hay que
     * deshacerlo, se anula el pago, y eso ya tiene su propio camino con su
     * propia reversión contable.
     */
    public function void(Check $check, string $reason): Check
    {
        if ($check->isVoided()) {
            throw TreasuryException::checkVoided($check);
        }

        if (trim($reason) === '') {
            throw new TreasuryException('Hay que indicar el motivo de la anulación.');
        }

        return DB::transaction(function () use ($check, $reason): Check {
            $check->forceFill([
                'status' => CheckStatus::Voided,
                'void_reason' => $reason,
            ])->save();

            $this->audit->log('voided', $check, reason: $reason, module: 'treasury');

            return $check->refresh();
        });
    }

    /**
     * Suma de los cheques que el banco todavía no había pagado a una fecha.
     */
    public function outstandingTotal(BankAccount $bankAccount, DateTimeInterface|string $asOf): Money
    {
        $date = CarbonImmutable::parse($asOf)->toDateString();

        $sum = Check::query()
            ->where('bank_account_id', $bankAccount->id)
            ->outstandingAt($date)
            ->sum('amount');

        return Money::of((string) $sum);
    }

    private function guardLive(Check $check): void
    {
        if ($check->isVoided()) {
            throw TreasuryException::checkVoided($check);
        }
    }
}
