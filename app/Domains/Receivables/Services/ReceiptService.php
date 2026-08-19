<?php

declare(strict_types=1);

namespace App\Domains\Receivables\Services;

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Enums\AccountMappingKey;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Accounting\Services\AccountMappingService;
use App\Domains\Accounting\Services\DocumentSeriesService;
use App\Domains\Assets\Services\WithholdingService;
use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Receivables\Exceptions\ReceivableException;
use App\Domains\Receivables\Models\Receipt;
use App\Domains\Receivables\Models\ReceiptApplication;
use App\Domains\Receivables\Models\Receivable;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Recibos de cobro.
 *
 * Un recibo aplica a una o varias facturas del mismo cliente. La suma de lo
 * aplicado debe coincidir exactamente con el importe del recibo: si sobrara,
 * habría dinero cobrado sin documento que lo respalde.
 */
final class ReceiptService
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly ReceivableService $receivables,
        private readonly AccountingEngine $engine,
        private readonly AccountMappingService $mappings,
        private readonly DocumentSeriesService $series,
        private readonly WithholdingService $withholdings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{receivable_id: int, amount: string}>  $applications
     */
    public function create(array $data, array $applications): Receipt
    {
        if ($applications === []) {
            throw ReceivableException::noApplications();
        }

        return DB::transaction(function () use ($data, $applications): Receipt {
            $applied = Money::sum(array_map(
                fn (array $row) => Money::of((string) $row['amount']),
                $applications,
            ));

            if (! $applied->isPositive()) {
                throw ReceivableException::noApplications();
            }

            $method = $data['payment_method'] instanceof PaymentMethod
                ? $data['payment_method']
                : PaymentMethod::from((string) $data['payment_method']);

            $receipt = new Receipt;
            $receipt->forceFill([
                'company_id' => $this->context->idOrFail(),
                'branch_id' => $data['branch_id'],
                'customer_id' => $data['customer_id'],
                'number' => $this->series->nextReceipt($data['branch_id']),
                'date' => CarbonImmutable::parse($data['date'])->toDateString(),
                'payment_method' => $method,
                'reference' => $data['reference'] ?? null,
                'deposit_account_id' => $data['deposit_account_id'],
                'amount' => $applied->toString(),
                'status' => 'issued',
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ])->save();

            foreach ($applications as $row) {
                $this->applyTo($receipt, (int) $row['receivable_id'], Money::of((string) $row['amount']));
            }

            // Lo que el cliente nos retuvo al pagarnos.
            $retentions = $this->withholdings->calculate($data['withholdings'] ?? [], $applied);

            $this->engine->post($this->buildJournalDraft($receipt, $applied, $retentions));

            if ($retentions !== []) {
                $this->withholdings->record($receipt, Receipt::SOURCE_TYPE, $retentions, $receipt->date);
            }

            $this->audit->log('created', $receipt, newValues: [
                'number' => $receipt->number,
                'amount' => $receipt->amount,
            ], module: 'receivables');

            return $receipt->refresh();
        });
    }

    /**
     * Anula el recibo: revierte la partida y devuelve el saldo a cada factura
     * que había cancelado.
     */
    public function void(Receipt $receipt, string $reason): Receipt
    {
        if ($receipt->isVoided()) {
            throw ReceivableException::receiptVoided($receipt);
        }

        if (trim($reason) === '') {
            throw ReceivableException::emptyReason();
        }

        return DB::transaction(function () use ($receipt, $reason): Receipt {
            $receipt->load('applications.receivable');

            foreach ($receipt->applications as $application) {
                $this->receivables->reversePayment(
                    $application->receivable,
                    $application->amountMoney(),
                );
            }

            $entry = $receipt->journalEntry();

            if ($entry !== null) {
                $this->voidOrReverse($entry, $reason);
            }

            $receipt->forceFill([
                'status' => 'voided',
                'voided_at' => now(),
                'voided_by' => Auth::id(),
                'void_reason' => $reason,
            ])->save();

            $this->audit->log('voided', $receipt, reason: $reason, module: 'receivables');

            return $receipt->refresh();
        });
    }

    private function applyTo(Receipt $receipt, int $receivableId, Money $amount): void
    {
        $receivable = Receivable::query()->findOrFail($receivableId);

        if ($receivable->customer_id !== $receipt->customer_id) {
            throw ReceivableException::foreignReceivable($receivable);
        }

        $this->receivables->applyPayment($receivable, $amount);

        $application = new ReceiptApplication;
        $application->forceFill([
            'company_id' => $receipt->company_id,
            'receipt_id' => $receipt->id,
            'receivable_id' => $receivable->id,
            'amount' => $amount->toString(),
        ])->save();
    }

    /**
     * Partida del cobro: entra el dinero, baja la cuenta del cliente.
     *
     * Cuando el cliente nos retiene, entra menos efectivo del que se cancela de
     * su cuenta: la diferencia no es un descuento sino un impuesto pagado por
     * anticipado, y por eso va a una cuenta de activo y no a una de ingreso
     * menor.
     *
     * @param  array<int, array{type: mixed, base: Money, amount: Money}>  $withholdings
     */
    private function buildJournalDraft(Receipt $receipt, Money $amount, array $withholdings = []): JournalDraft
    {
        $concept = sprintf('Recibo %s — %s', $receipt->number, $receipt->customer->name);

        $draft = JournalDraft::on($receipt->date, $concept)
            ->inBranch($receipt->branch_id)
            ->withReference($receipt->number)
            ->fromDocument(Receipt::SOURCE_TYPE, $receipt->id);

        $retained = Money::zero();

        foreach ($withholdings as $row) {
            $draft->debit($row['type']->account_id, $row['amount'], 'Retención '.$row['type']->code);
            $retained = $retained->plus($row['amount']);
        }

        return $draft
            ->debit($receipt->deposit_account_id, $amount->minus($retained), $receipt->payment_method->label())
            ->credit(
                $this->mappings->resolveId(AccountMappingKey::SalesReceivable),
                $amount,
                'Abono del cliente',
            );
    }

    private function voidOrReverse(JournalEntry $entry, string $reason): void
    {
        if ($entry->period->acceptsPostings()) {
            $this->engine->void($entry, $reason);

            return;
        }

        $this->engine->reverse($entry, $reason);
    }
}
