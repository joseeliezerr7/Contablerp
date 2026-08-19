<?php

declare(strict_types=1);

namespace App\Domains\Payables\Services;

use App\Domains\Partners\Models\Supplier;
use App\Domains\Payables\Enums\PayableStatus;
use App\Domains\Payables\Exceptions\PayableException;
use App\Domains\Payables\Models\Payable;
use App\Domains\Purchases\Models\Purchase;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cuentas por pagar. Espejo exacto de las cuentas por cobrar: el saldo lo
 * deriva la base de datos y este servicio solo mueve `paid_amount` bajo
 * bloqueo de fila.
 */
final class PayableService
{
    public function openFor(Purchase $purchase): Payable
    {
        $payable = new Payable;
        $payable->forceFill([
            'company_id' => $purchase->company_id,
            'supplier_id' => $purchase->supplier_id,
            'purchase_id' => $purchase->id,
            'document_number' => $purchase->supplier_invoice_number,
            'date' => $purchase->date->toDateString(),
            'due_date' => $purchase->due_date->toDateString(),
            'original_amount' => $purchase->total,
            'paid_amount' => '0',
            'status' => PayableStatus::Open,
        ])->save();

        return $payable;
    }

    public function applyPayment(Payable $payable, Money $amount): Payable
    {
        return DB::transaction(function () use ($payable, $amount): Payable {
            $locked = Payable::query()->whereKey($payable->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PayableStatus::Voided) {
                throw PayableException::voidedDocument($locked);
            }

            if ($amount->greaterThan($locked->balanceAmount())) {
                throw PayableException::overApplied($locked, $amount);
            }

            $paid = $locked->paidAmount()->plus($amount);

            $locked->forceFill([
                'paid_amount' => $paid->toString(),
                'status' => $paid->equals($locked->originalAmount())
                    ? PayableStatus::Paid
                    : PayableStatus::Open,
            ])->save();

            return $locked->refresh();
        });
    }

    public function reversePayment(Payable $payable, Money $amount): Payable
    {
        return DB::transaction(function () use ($payable, $amount): Payable {
            $locked = Payable::query()->whereKey($payable->id)->lockForUpdate()->firstOrFail();

            $paid = $locked->paidAmount()->minus($amount);

            if ($paid->isNegative()) {
                $paid = Money::zero();
            }

            $locked->forceFill([
                'paid_amount' => $paid->toString(),
                'status' => $locked->status === PayableStatus::Voided
                    ? PayableStatus::Voided
                    : PayableStatus::Open,
            ])->save();

            return $locked->refresh();
        });
    }

    public function cancel(Payable $payable): Payable
    {
        if ($payable->paidAmount()->isPositive()) {
            throw PayableException::cannotCancelWithPayments($payable);
        }

        $payable->forceFill(['status' => PayableStatus::Voided])->save();

        return $payable->refresh();
    }

    /**
     * Antigüedad de saldos por pagar. Los tramos son los mismos que en cobrar,
     * para que ambos reportes se lean igual.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, Money>, buckets: array<string, string>, as_of: CarbonImmutable}
     */
    public function aging(DateTimeInterface|string|null $asOf = null, ?int $supplierId = null): array
    {
        $asOf = CarbonImmutable::parse($asOf ?? now())->startOfDay();

        $payables = Payable::query()
            ->with('supplier:id,code,name,trade_name')
            ->outstanding()
            ->when($supplierId !== null, fn ($q) => $q->where('supplier_id', $supplierId))
            ->orderBy('due_date')
            ->get();

        $buckets = ['current' => 'Corriente', 'd30' => '1–30', 'd60' => '31–60', 'd90' => '61–90', 'over' => 'Más de 90'];

        $bySupplier = [];
        $totals = array_map(fn () => Money::zero(), $buckets);
        $totals['total'] = Money::zero();

        foreach ($payables as $payable) {
            $bucket = $this->bucketFor($payable->daysOverdue($asOf));
            $balance = $payable->balanceAmount();
            $id = $payable->supplier_id;

            if (! isset($bySupplier[$id])) {
                $bySupplier[$id] = [
                    'supplier' => $payable->supplier,
                    ...array_map(fn () => Money::zero(), $buckets),
                    'total' => Money::zero(),
                ];
            }

            $bySupplier[$id][$bucket] = $bySupplier[$id][$bucket]->plus($balance);
            $bySupplier[$id]['total'] = $bySupplier[$id]['total']->plus($balance);

            $totals[$bucket] = $totals[$bucket]->plus($balance);
            $totals['total'] = $totals['total']->plus($balance);
        }

        return [
            'rows' => collect(array_values($bySupplier))
                ->sortBy(fn (array $row) => $row['supplier']->name)
                ->values(),
            'totals' => $totals,
            'buckets' => $buckets,
            'as_of' => $asOf,
        ];
    }

    /**
     * Estado de cuenta del proveedor.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, opening: Money, closing: Money}
     */
    public function statement(Supplier $supplier, DateTimeInterface|string $from, DateTimeInterface|string $to): array
    {
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->endOfDay();

        $opening = $this->balanceAt($supplier, $from->subDay());

        $charges = Payable::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', '!=', PayableStatus::Voided)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->map(fn (Payable $p) => [
                'date' => $p->date,
                'document' => $p->document_number,
                'concept' => 'Factura de compra',
                'charge' => $p->originalAmount(),
                'payment' => Money::zero(),
            ]);

        $payments = DB::table('payment_applications as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->join('payables as pay', 'pay.id', '=', 'pa.payable_id')
            ->where('pay.supplier_id', $supplier->id)
            ->where('p.status', 'issued')
            ->whereBetween('p.date', [$from->toDateString(), $to->toDateString()])
            ->select('p.date', 'p.number', 'pay.document_number', 'pa.amount')
            ->get()
            ->map(fn ($row) => [
                'date' => CarbonImmutable::parse($row->date),
                'document' => $row->number,
                'concept' => "Pago a {$row->document_number}",
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

    public function balanceAt(Supplier $supplier, DateTimeInterface|string $date): Money
    {
        $date = CarbonImmutable::parse($date)->endOfDay()->toDateString();

        $charged = Payable::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', '!=', PayableStatus::Voided)
            ->where('date', '<=', $date)
            ->sum('original_amount');

        $paid = DB::table('payment_applications as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->join('payables as pay', 'pay.id', '=', 'pa.payable_id')
            ->where('pay.supplier_id', $supplier->id)
            ->where('p.status', 'issued')
            ->where('p.date', '<=', $date)
            ->sum('pa.amount');

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
