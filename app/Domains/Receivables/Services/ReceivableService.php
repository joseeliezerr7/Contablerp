<?php

declare(strict_types=1);

namespace App\Domains\Receivables\Services;

use App\Domains\Partners\Models\Customer;
use App\Domains\Receivables\Enums\ReceivableStatus;
use App\Domains\Receivables\Exceptions\ReceivableException;
use App\Domains\Receivables\Models\Receivable;
use App\Domains\Sales\Models\Sale;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cuentas por cobrar: apertura, aplicación de abonos y consultas de saldo.
 *
 * El saldo no se guarda: la tabla lo deriva de `original_amount - paid_amount`
 * mediante una columna generada, así que es imposible que se desincronice.
 * Este servicio solo mueve `paid_amount`, siempre bajo bloqueo de fila.
 */
final class ReceivableService
{
    public function openFor(Sale $sale): Receivable
    {
        $receivable = new Receivable;
        $receivable->forceFill([
            'company_id' => $sale->company_id,
            'customer_id' => $sale->customer_id,
            'sale_id' => $sale->id,
            'document_number' => $sale->number,
            'date' => $sale->date->toDateString(),
            'due_date' => $sale->due_date->toDateString(),
            'original_amount' => $sale->total,
            'paid_amount' => '0',
            'status' => ReceivableStatus::Open,
        ])->save();

        return $receivable;
    }

    /**
     * Aplica un abono. Bloquea la fila para que dos cobros simultáneos no
     * puedan sobrepasar juntos el saldo del documento.
     */
    public function applyPayment(Receivable $receivable, Money $amount): Receivable
    {
        return DB::transaction(function () use ($receivable, $amount): Receivable {
            $locked = Receivable::query()->whereKey($receivable->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ReceivableStatus::Voided) {
                throw ReceivableException::voidedDocument($locked);
            }

            if ($amount->greaterThan($locked->balanceAmount())) {
                throw ReceivableException::overApplied($locked, $amount);
            }

            $paid = $locked->paidAmount()->plus($amount);

            $locked->forceFill([
                'paid_amount' => $paid->toString(),
                'status' => $paid->equals($locked->originalAmount())
                    ? ReceivableStatus::Paid
                    : ReceivableStatus::Open,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Devuelve un abono al anular un recibo.
     */
    public function reversePayment(Receivable $receivable, Money $amount): Receivable
    {
        return DB::transaction(function () use ($receivable, $amount): Receivable {
            $locked = Receivable::query()->whereKey($receivable->id)->lockForUpdate()->firstOrFail();

            $paid = $locked->paidAmount()->minus($amount);

            if ($paid->isNegative()) {
                $paid = Money::zero();
            }

            $locked->forceFill([
                'paid_amount' => $paid->toString(),
                'status' => $locked->status === ReceivableStatus::Voided
                    ? ReceivableStatus::Voided
                    : ReceivableStatus::Open,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Aplica una nota de crédito.
     *
     * Va contra `credited_amount` y **no** contra `paid_amount`, aunque las dos
     * cosas bajen el saldo. Una nota de crédito no es un cobro: no entró dinero.
     * Meterla en lo cobrado inflaría la recaudación del mes, le daría comisión
     * al vendedor sobre una devolución y le pondría al flujo de efectivo una
     * entrada que nunca ocurrió.
     */
    public function applyCredit(Receivable $receivable, Money $amount): Receivable
    {
        return DB::transaction(function () use ($receivable, $amount): Receivable {
            $locked = Receivable::query()->whereKey($receivable->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ReceivableStatus::Voided) {
                throw ReceivableException::voidedDocument($locked);
            }

            if ($amount->greaterThan($locked->balanceAmount())) {
                throw ReceivableException::overApplied($locked, $amount);
            }

            $credited = $locked->creditedAmount()->plus($amount);
            $settled = $locked->paidAmount()->plus($credited);

            $locked->forceFill([
                'credited_amount' => $credited->toString(),
                // Un documento acreditado por completo queda saldado aunque no
                // se haya cobrado un lempira: ya no hay nada que cobrar.
                'status' => $settled->equals($locked->originalAmount())
                    ? ReceivableStatus::Paid
                    : ReceivableStatus::Open,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Deshace una nota de crédito anulada.
     */
    public function reverseCredit(Receivable $receivable, Money $amount): Receivable
    {
        return DB::transaction(function () use ($receivable, $amount): Receivable {
            $locked = Receivable::query()->whereKey($receivable->id)->lockForUpdate()->firstOrFail();

            $credited = $locked->creditedAmount()->minus($amount);

            if ($credited->isNegative()) {
                $credited = Money::zero();
            }

            $locked->forceFill([
                'credited_amount' => $credited->toString(),
                'status' => $locked->status === ReceivableStatus::Voided
                    ? ReceivableStatus::Voided
                    : ReceivableStatus::Open,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Anula la cuenta por cobrar de una factura anulada.
     */
    public function cancel(Receivable $receivable): Receivable
    {
        if ($receivable->paidAmount()->isPositive()) {
            throw ReceivableException::cannotCancelWithPayments($receivable);
        }

        $receivable->forceFill(['status' => ReceivableStatus::Voided])->save();

        return $receivable->refresh();
    }

    /**
     * Antigüedad de saldos: corriente y tramos de 30 días.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, Money>}
     */
    public function aging(DateTimeInterface|string|null $asOf = null, ?int $customerId = null): array
    {
        $asOf = CarbonImmutable::parse($asOf ?? now())->startOfDay();

        $receivables = Receivable::query()
            ->with('customer:id,code,name,trade_name')
            ->outstanding()
            ->when($customerId !== null, fn ($q) => $q->where('customer_id', $customerId))
            ->orderBy('due_date')
            ->get();

        $buckets = ['current' => 'Corriente', 'd30' => '1–30', 'd60' => '31–60', 'd90' => '61–90', 'over' => 'Más de 90'];

        $byCustomer = [];
        $totals = array_map(fn () => Money::zero(), $buckets);
        $totals['total'] = Money::zero();

        foreach ($receivables as $receivable) {
            $bucket = $this->bucketFor($receivable->daysOverdue($asOf));
            $balance = $receivable->balanceAmount();
            $customerId = $receivable->customer_id;

            if (! isset($byCustomer[$customerId])) {
                $byCustomer[$customerId] = [
                    'customer' => $receivable->customer,
                    ...array_map(fn () => Money::zero(), $buckets),
                    'total' => Money::zero(),
                ];
            }

            $byCustomer[$customerId][$bucket] = $byCustomer[$customerId][$bucket]->plus($balance);
            $byCustomer[$customerId]['total'] = $byCustomer[$customerId]['total']->plus($balance);

            $totals[$bucket] = $totals[$bucket]->plus($balance);
            $totals['total'] = $totals['total']->plus($balance);
        }

        return [
            'rows' => collect(array_values($byCustomer))
                ->sortBy(fn (array $row) => $row['customer']->name)
                ->values(),
            'totals' => $totals,
            'buckets' => $buckets,
            'as_of' => $asOf,
        ];
    }

    /**
     * Estado de cuenta de un cliente: cargos, abonos y saldo acumulado.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, opening: Money, closing: Money}
     */
    public function statement(Customer $customer, DateTimeInterface|string $from, DateTimeInterface|string $to): array
    {
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->endOfDay();

        $opening = $this->balanceAt($customer, $from->subDay());

        $charges = Receivable::query()
            ->where('customer_id', $customer->id)
            ->where('status', '!=', ReceivableStatus::Voided)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->map(fn (Receivable $r) => [
                'date' => $r->date,
                'document' => $r->document_number,
                'concept' => 'Factura',
                'charge' => $r->originalAmount(),
                'payment' => Money::zero(),
            ]);

        $payments = DB::table('receipt_applications as ra')
            ->join('receipts as r', 'r.id', '=', 'ra.receipt_id')
            ->join('receivables as rec', 'rec.id', '=', 'ra.receivable_id')
            ->where('rec.customer_id', $customer->id)
            ->where('r.status', 'issued')
            ->whereBetween('r.date', [$from->toDateString(), $to->toDateString()])
            ->select('r.date', 'r.number', 'rec.document_number', 'ra.amount')
            ->get()
            ->map(fn ($row) => [
                'date' => CarbonImmutable::parse($row->date),
                'document' => $row->number,
                'concept' => "Abono a {$row->document_number}",
                'charge' => Money::zero(),
                'payment' => Money::of((string) $row->amount),
            ]);

        $running = $opening;

        $rows = $charges->concat($payments)
            ->sortBy('date')
            ->values()
            ->map(function (array $row) use (&$running): array {
                $running = $running->plus($row['charge'])->minus($row['payment']);

                return [...$row, 'balance' => $running];
            });

        return ['rows' => $rows, 'opening' => $opening, 'closing' => $running];
    }

    /**
     * Saldo del cliente a una fecha.
     */
    public function balanceAt(Customer $customer, DateTimeInterface|string $date): Money
    {
        $date = CarbonImmutable::parse($date)->endOfDay()->toDateString();

        $charged = Receivable::query()
            ->where('customer_id', $customer->id)
            ->where('status', '!=', ReceivableStatus::Voided)
            ->where('date', '<=', $date)
            ->sum('original_amount');

        $paid = DB::table('receipt_applications as ra')
            ->join('receipts as r', 'r.id', '=', 'ra.receipt_id')
            ->join('receivables as rec', 'rec.id', '=', 'ra.receivable_id')
            ->where('rec.customer_id', $customer->id)
            ->where('r.status', 'issued')
            ->where('r.date', '<=', $date)
            ->sum('ra.amount');

        return Money::of((string) $charged)->minus(Money::of((string) $paid));
    }

    private function bucketFor(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => 'd30',
            $daysOverdue <= 60 => 'd60',
            $daysOverdue <= 90 => 'd90',
            default => 'over',
        };
    }
}
