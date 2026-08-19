<?php

declare(strict_types=1);

namespace App\Domains\Treasury\Exceptions;

use App\Domains\Accounting\Models\Account;
use App\Domains\Treasury\Models\BankAccount;
use App\Domains\Treasury\Models\BankReconciliation;
use App\Domains\Treasury\Models\CashSession;
use App\Domains\Treasury\Models\Check;
use RuntimeException;

class TreasuryException extends RuntimeException
{
    public static function accountNotCash(Account $account): self
    {
        return new self(sprintf(
            'La cuenta %s no está marcada como efectivo o equivalente, así que no puede ser una cuenta de tesorería.',
            $account->label(),
        ));
    }

    public static function accountAlreadyLinked(Account $account): self
    {
        return new self(sprintf(
            'La cuenta %s ya pertenece a otra cuenta bancaria. Cada cuenta bancaria necesita la suya para poder conciliarse por separado.',
            $account->label(),
        ));
    }

    public static function doesNotIssueChecks(BankAccount $bankAccount): self
    {
        return new self(sprintf(
            'La cuenta %s no tiene chequera configurada.',
            $bankAccount->label(),
        ));
    }

    public static function checkVoided(Check $check): self
    {
        return new self($check->label().' está anulado.');
    }

    public static function checkAlreadyCleared(Check $check): self
    {
        return new self($check->label().' ya figura como cobrado.');
    }

    public static function clearedBeforeIssued(Check $check): self
    {
        return new self(sprintf(
            'No se puede cobrar %s antes de la fecha en que se giró (%s).',
            $check->label(),
            $check->date->format('d/m/Y'),
        ));
    }

    public static function reconciliationNotDraft(BankReconciliation $reconciliation): self
    {
        return new self('La '.lcfirst($reconciliation->label()).' ya está cerrada.');
    }

    public static function reconciliationNotClosed(BankReconciliation $reconciliation): self
    {
        return new self('La '.lcfirst($reconciliation->label()).' no está cerrada.');
    }

    public static function reconciliationDoesNotBalance(BankReconciliation $reconciliation): self
    {
        return new self(sprintf(
            'La conciliación no cuadra: quedan %s sin explicar. Revisa las partidas marcadas y el saldo del extracto antes de cerrarla.',
            $reconciliation->differenceAmount()->format(),
        ));
    }

    public static function lineNotFromBankAccount(): self
    {
        return new self('Esa partida no pertenece a la cuenta bancaria que se está conciliando.');
    }

    public static function lineAfterCutoff(): self
    {
        return new self('Esa partida es posterior a la fecha de corte del extracto.');
    }

    public static function lineAlreadyReconciled(): self
    {
        return new self('Esa partida ya fue conciliada en otra conciliación.');
    }

    public static function cashSessionAlreadyOpen(Account $account): self
    {
        return new self(sprintf(
            'La caja %s ya tiene una sesión abierta. Ciérrala antes de abrir otra.',
            $account->label(),
        ));
    }

    public static function cashSessionNotOpen(CashSession $session): self
    {
        return new self('La '.lcfirst($session->label()).' ya está cerrada.');
    }

    public static function negativeAmount(): self
    {
        return new self('El importe no puede ser negativo.');
    }
}
