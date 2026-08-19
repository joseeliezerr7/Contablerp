<?php

declare(strict_types=1);

namespace App\Domains\Payables\Services;

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Enums\AccountMappingKey;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Accounting\Services\AccountMappingService;
use App\Domains\Accounting\Services\DocumentSeriesService;
use App\Domains\Assets\Services\WithholdingService;
use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Payables\Exceptions\PayableException;
use App\Domains\Payables\Models\Payable;
use App\Domains\Payables\Models\Payment;
use App\Domains\Payables\Models\PaymentApplication;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Treasury\Models\BankAccount;
use App\Domains\Treasury\Services\CheckService;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Pagos a proveedores. Espejo de los recibos de cobro.
 */
final class PaymentService
{
    public const SERIES = 'supplier_payment';

    public function __construct(
        private readonly CompanyContext $context,
        private readonly PayableService $payables,
        private readonly AccountingEngine $engine,
        private readonly AccountMappingService $mappings,
        private readonly DocumentSeriesService $series,
        private readonly CheckService $checks,
        private readonly WithholdingService $withholdings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{payable_id: int, amount: string}>  $applications
     */
    public function create(array $data, array $applications): Payment
    {
        if ($applications === []) {
            throw PayableException::noApplications();
        }

        return DB::transaction(function () use ($data, $applications): Payment {
            $applied = Money::sum(array_map(
                fn (array $row) => Money::of((string) $row['amount']),
                $applications,
            ));

            if (! $applied->isPositive()) {
                throw PayableException::noApplications();
            }

            $method = $data['payment_method'] instanceof PaymentMethod
                ? $data['payment_method']
                : PaymentMethod::from((string) $data['payment_method']);

            $payment = new Payment;
            $payment->forceFill([
                'company_id' => $this->context->idOrFail(),
                'branch_id' => $data['branch_id'],
                'supplier_id' => $data['supplier_id'],
                'number' => $this->series->next(self::SERIES, '*', $data['branch_id'], 'PAG-'),
                'date' => CarbonImmutable::parse($data['date'])->toDateString(),
                'payment_method' => $method,
                'reference' => $data['reference'] ?? null,
                'payment_account_id' => $data['payment_account_id'],
                'amount' => $applied->toString(),
                'status' => 'issued',
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ])->save();

            foreach ($applications as $row) {
                $this->applyTo($payment, (int) $row['payable_id'], Money::of((string) $row['amount']));
            }

            // Las retenciones se practican al pagar, no al facturar: la deuda
            // con el proveedor es el total de su factura, y lo que cambia es
            // cuánto sale del banco.
            $retentions = $this->withholdings->calculate($data['withholdings'] ?? [], $applied);

            $this->engine->post($this->buildJournalDraft($payment, $applied, $retentions));

            if ($retentions !== []) {
                $this->withholdings->record($payment, Payment::SOURCE_TYPE, $retentions, $payment->date);
            }

            $netPaid = $applied->minus($this->withholdings->total($retentions));

            $this->issueCheckIfNeeded($payment, $netPaid, $data);

            $this->audit->log('created', $payment, newValues: [
                'number' => $payment->number,
                'amount' => $payment->amount,
            ], module: 'payables');

            return $payment->refresh();
        });
    }

    public function void(Payment $payment, string $reason): Payment
    {
        if ($payment->isVoided()) {
            throw PayableException::paymentVoided($payment);
        }

        if (trim($reason) === '') {
            throw PayableException::emptyReason();
        }

        return DB::transaction(function () use ($payment, $reason): Payment {
            $payment->load('applications.payable');

            foreach ($payment->applications as $application) {
                $this->payables->reversePayment($application->payable, $application->amountMoney());
            }

            $entry = $payment->journalEntry();

            if ($entry !== null) {
                $this->voidOrReverse($entry, $reason);
            }

            $payment->forceFill([
                'status' => 'voided',
                'voided_at' => now(),
                'voided_by' => Auth::id(),
                'void_reason' => $reason,
            ])->save();

            $this->audit->log('voided', $payment, reason: $reason, module: 'payables');

            return $payment->refresh();
        });
    }

    private function applyTo(Payment $payment, int $payableId, Money $amount): void
    {
        $payable = Payable::query()->findOrFail($payableId);

        if ($payable->supplier_id !== $payment->supplier_id) {
            throw PayableException::foreignPayable($payable);
        }

        $this->payables->applyPayment($payable, $amount);

        $application = new PaymentApplication;
        $application->forceFill([
            'company_id' => $payment->company_id,
            'payment_id' => $payment->id,
            'payable_id' => $payable->id,
            'amount' => $amount->toString(),
        ])->save();
    }

    /**
     * Partida del pago: baja la deuda con el proveedor, sale el dinero.
     *
     * Con retención el asiento tiene tres patas: se cancela la deuda completa,
     * sale del banco solo el neto, y la diferencia queda como retención por
     * pagar al fisco. El proveedor queda saldado por el total de su factura,
     * que es lo que él espera ver.
     *
     * @param  array<int, array{type: mixed, base: Money, amount: Money}>  $withholdings
     */
    private function buildJournalDraft(Payment $payment, Money $amount, array $withholdings = []): JournalDraft
    {
        $concept = sprintf('Pago %s — %s', $payment->number, $payment->supplier->name);

        $draft = JournalDraft::on($payment->date, $concept)
            ->inBranch($payment->branch_id)
            ->withReference($payment->number)
            ->fromDocument(Payment::SOURCE_TYPE, $payment->id)
            ->debit(
                $this->mappings->resolveId(AccountMappingKey::PurchasesPayable),
                $amount,
                'Pago al proveedor',
            );

        $retained = Money::zero();

        foreach ($withholdings as $row) {
            $draft->credit($row['type']->account_id, $row['amount'], 'Retención '.$row['type']->code);
            $retained = $retained->plus($row['amount']);
        }

        return $draft->credit(
            $payment->payment_account_id,
            $amount->minus($retained),
            $payment->payment_method->label(),
        );
    }

    /**
     * Gira el cheque cuando el pago sale con cheque de una cuenta bancaria que
     * tiene chequera configurada.
     *
     * El cheque no genera partida propia: el asiento del pago ya sacó el dinero
     * del banco. Lo que aporta el cheque es el seguimiento —a quién se giró, si
     * ya se entregó, si el banco lo cobró—, que es lo que después explica una
     * diferencia en la conciliación.
     *
     * @param  array<string, mixed>  $data
     */
    private function issueCheckIfNeeded(Payment $payment, Money $amount, array $data): void
    {
        if ($payment->payment_method !== PaymentMethod::Check) {
            return;
        }

        $bankAccount = BankAccount::query()
            ->where('account_id', $payment->payment_account_id)
            ->first();

        // Sin cuenta bancaria dada de alta, o sin chequera, el pago sigue
        // siendo válido: se anota la referencia a mano como hasta ahora.
        if ($bankAccount === null || ! $bankAccount->issuesChecks()) {
            return;
        }

        $check = $this->checks->issue(
            $bankAccount,
            $amount,
            $payment->supplier->name,
            $payment->date,
            Payment::SOURCE_TYPE,
            $payment->id,
            // Si el usuario tecleó el número del cheque, manda el suyo.
            number: $this->explicitCheckNumber($data),
        );

        if ($payment->reference === null || trim((string) $payment->reference) === '') {
            $payment->forceFill(['reference' => $check->number])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function explicitCheckNumber(array $data): ?string
    {
        $number = trim((string) ($data['check_number'] ?? ''));

        return $number === '' ? null : $number;
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
