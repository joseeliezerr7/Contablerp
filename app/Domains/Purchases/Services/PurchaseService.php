<?php

declare(strict_types=1);

namespace App\Domains\Purchases\Services;

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Enums\AccountMappingKey;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Accounting\Services\AccountMappingService;
use App\Domains\Accounting\Services\DocumentSeriesService;
use App\Domains\Catalog\Models\Product;
use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Inventory\DataTransfer\StockMovementDraft;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Services\InventoryService;
use App\Domains\Payables\Services\PayableService;
use App\Domains\Purchases\Enums\PurchaseStatus;
use App\Domains\Purchases\Exceptions\PurchaseException;
use App\Domains\Purchases\Models\Purchase;
use App\Domains\Purchases\Models\PurchaseItem;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Taxation\Services\TaxResolver;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Registro y anulación de compras a proveedores.
 *
 * El costo entra **neto de descuentos**, a diferencia de las ventas, donde el
 * ingreso se acredita bruto y el descuento se carga aparte. La razón no es de
 * simetría sino de valuación: el inventario debe quedar registrado a lo que
 * realmente costó, porque de ese importe sale el costo promedio de la Fase 5.
 * Un descuento comercial reduce el costo, no es un gasto separado.
 */
final class PurchaseService
{
    public const SERIES = 'purchase';

    public function __construct(
        private readonly CompanyContext $context,
        private readonly TaxResolver $taxes,
        private readonly AccountingEngine $engine,
        private readonly AccountMappingService $mappings,
        private readonly DocumentSeriesService $series,
        private readonly PayableService $payables,
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function saveDraft(array $data, array $lines): Purchase
    {
        return DB::transaction(function () use ($data, $lines): Purchase {
            $purchase = new Purchase;
            $purchase->forceFill([
                ...$this->headerAttributes($data),
                'company_id' => $this->context->idOrFail(),
                'status' => PurchaseStatus::Draft,
                'created_by' => Auth::id(),
            ])->save();

            $this->replaceLines($purchase, $lines);

            return $purchase->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function updateDraft(Purchase $purchase, array $data, array $lines): Purchase
    {
        if (! $purchase->isDraft()) {
            throw PurchaseException::notDraft($purchase);
        }

        return DB::transaction(function () use ($purchase, $data, $lines): Purchase {
            $purchase->forceFill($this->headerAttributes($data))->save();
            $this->replaceLines($purchase, $lines);

            return $purchase->refresh();
        });
    }

    public function deleteDraft(Purchase $purchase): void
    {
        if (! $purchase->isDraft()) {
            throw PurchaseException::notDraft($purchase);
        }

        DB::transaction(function () use ($purchase): void {
            $purchase->items()->delete();
            $purchase->delete();
        });
    }

    /**
     * Recibe la compra: correlativo interno, cuenta por pagar y partida.
     */
    public function receive(Purchase $purchase): Purchase
    {
        if ($purchase->isReceived()) {
            throw PurchaseException::alreadyReceived($purchase);
        }

        if (! $purchase->isDraft()) {
            throw PurchaseException::notDraft($purchase);
        }

        return DB::transaction(function () use ($purchase): Purchase {
            $purchase->load(['items.product', 'items.tax', 'supplier', 'branch']);

            if ($purchase->items->isEmpty()) {
                throw PurchaseException::noLines();
            }

            if (trim((string) $purchase->supplier_invoice_number) === '') {
                throw PurchaseException::missingInvoiceNumber();
            }

            if (! $purchase->supplier->is_active) {
                throw PurchaseException::inactiveSupplier($purchase->supplier);
            }

            $this->guardInvoiceNotDuplicated($purchase);

            $this->recalculate($purchase);
            $purchase->refresh()->load(['items.product', 'items.tax']);

            if (! $purchase->isOnCredit() && $purchase->payment_account_id === null) {
                throw PurchaseException::missingPaymentAccount();
            }

            $dueDate = $purchase->isOnCredit()
                ? CarbonImmutable::parse($purchase->date)->addDays($purchase->credit_days)
                : CarbonImmutable::parse($purchase->date);

            $purchase->forceFill([
                'number' => $this->series->next(self::SERIES, '*', $purchase->branch_id, 'COM-'),
                'due_date' => $dueDate->toDateString(),
                'status' => PurchaseStatus::Received,
                'received_at' => now(),
                'received_by' => Auth::id(),
            ])->save();

            if ($purchase->isOnCredit()) {
                $this->payables->openFor($purchase);
            }

            $this->engine->post($this->buildJournalDraft($purchase));
            $this->receiveIntoStock($purchase);

            $this->audit->log('received', $purchase, newValues: [
                'number' => $purchase->number,
                'supplier_invoice' => $purchase->supplier_invoice_number,
                'total' => $purchase->total,
            ], module: 'purchases');

            return $purchase->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function createAndReceive(array $data, array $lines): Purchase
    {
        return DB::transaction(function () use ($data, $lines): Purchase {
            $purchase = $this->saveDraft($data, $lines);

            return $this->receive($purchase);
        });
    }

    public function void(Purchase $purchase, string $reason): Purchase
    {
        if ($purchase->isVoided()) {
            throw PurchaseException::alreadyVoided($purchase);
        }

        if (! $purchase->isReceived()) {
            throw PurchaseException::notReceived();
        }

        if (trim($reason) === '') {
            throw PurchaseException::emptyReason();
        }

        return DB::transaction(function () use ($purchase, $reason): Purchase {
            $payable = $purchase->payable;

            if ($payable !== null && $payable->paidAmount()->isPositive()) {
                throw PurchaseException::hasPayments($purchase);
            }

            $entry = $purchase->journalEntry();

            if ($entry !== null) {
                $this->voidOrReverse($entry, $reason);
            }

            $this->returnFromStock($purchase);

            if ($payable !== null) {
                $this->payables->cancel($payable);
            }

            $purchase->forceFill([
                'status' => PurchaseStatus::Voided,
                'voided_at' => now(),
                'voided_by' => Auth::id(),
                'void_reason' => $reason,
            ])->save();

            $this->audit->log('voided', $purchase, reason: $reason, module: 'purchases');

            return $purchase->refresh();
        });
    }

    public function recalculate(Purchase $purchase): Purchase
    {
        $purchase->loadMissing('items.tax');

        $calculations = [];

        foreach ($purchase->items as $item) {
            $calculation = $this->taxes->calculateLine(
                (string) $item->quantity,
                (string) $item->unit_price,
                (string) $item->discount_rate,
                $item->tax,
            );

            $item->forceFill([
                'discount_amount' => $calculation->discountAmount->toString(),
                'tax_rate' => $calculation->taxRate,
                'tax_amount' => $calculation->taxAmount->toString(),
                'subtotal' => $calculation->subtotal->toString(),
                'total' => $calculation->total->toString(),
            ])->save();

            $calculations[] = $calculation;
        }

        $totals = $this->taxes->totals($calculations);

        $purchase->forceFill([
            'subtotal' => $totals['subtotal']->toString(),
            'discount_total' => $totals['discount']->toString(),
            'tax_total' => $totals['tax']->toString(),
            'total' => $totals['total']->toString(),
        ])->save();

        return $purchase;
    }

    /**
     * Partida de la compra:
     *
     *   D  Inventario o gasto      (costo neto, por cuenta)
     *   D  Impuesto acreditable    (crédito fiscal)
     *      C  Proveedores o banco  (total)
     */
    private function buildJournalDraft(Purchase $purchase): JournalDraft
    {
        $concept = sprintf(
            'Compra %s — %s (factura %s)',
            $purchase->number,
            $purchase->supplier->name,
            $purchase->supplier_invoice_number,
        );

        $draft = JournalDraft::on($purchase->date, $concept)
            ->inBranch($purchase->branch_id)
            ->withReference($purchase->supplier_invoice_number)
            ->fromDocument(Purchase::SOURCE_TYPE, $purchase->id);

        foreach ($this->costByAccount($purchase) as $accountId => $amount) {
            $draft->debit($accountId, $amount, 'Compra');
        }

        foreach ($this->taxByAccount($purchase) as $accountId => $amount) {
            $draft->debit($accountId, $amount, 'Impuesto acreditable');
        }

        if ($purchase->isOnCredit()) {
            $draft->credit(
                $this->mappings->resolveId(AccountMappingKey::PurchasesPayable),
                $purchase->totalAmount(),
                "Factura {$purchase->supplier_invoice_number}",
            );
        } else {
            $draft->credit(
                $purchase->payment_account_id,
                $purchase->totalAmount(),
                'Compra de contado',
            );
        }

        return $draft;
    }

    /**
     * Costo neto agrupado por cuenta.
     *
     * Cada línea decide su destino: la cuenta indicada en la línea, la del
     * producto, o —según lleve o no control de existencias— inventario o el
     * gasto genérico de compras.
     *
     * @return array<int, Money>
     */
    private function costByAccount(Purchase $purchase): array
    {
        $totals = [];

        foreach ($purchase->items as $item) {
            $accountId = $this->accountFor($item);
            $totals[$accountId] = ($totals[$accountId] ?? Money::zero())->plus($item->subtotalAmount());
        }

        return array_filter($totals, fn (Money $amount) => $amount->isPositive());
    }

    private function accountFor(PurchaseItem $item): int
    {
        if ($item->expense_account_id !== null) {
            return $item->expense_account_id;
        }

        if ($item->goesToInventory()) {
            return $item->product?->inventory_account_id
                ?? $this->mappings->resolveId(AccountMappingKey::InventoryAsset);
        }

        return $item->product?->cost_account_id
            ?? $this->mappings->resolveId(AccountMappingKey::PurchasesExpense);
    }

    /**
     * Si la línea entra al kardex.
     *
     * Es la misma condición que manda el costo a la cuenta de inventario en
     * `accountFor()`, y tiene que serlo: una línea cargada a una cuenta de
     * gasto ya se consumió: darla de alta en existencias la contaría dos veces.
     */
    private function entersInventory(PurchaseItem $item): bool
    {
        return $item->expense_account_id === null && $item->goesToInventory();
    }

    /*
    |--------------------------------------------------------------------------
    | Inventario
    |--------------------------------------------------------------------------
    */

    /**
     * Da entrada a la mercadería con el costo neto de la factura.
     *
     * El valor que entra al kardex es `subtotal` de la línea, exactamente el
     * mismo importe que se acaba de cargar a la cuenta de inventario. No se
     * recalcula a partir del costo unitario.
     */
    private function receiveIntoStock(Purchase $purchase): void
    {
        $lines = $purchase->items->filter(fn (PurchaseItem $item) => $this->entersInventory($item));

        if ($lines->isEmpty()) {
            return;
        }

        if ($purchase->warehouse_id === null) {
            throw PurchaseException::missingWarehouse();
        }

        foreach ($lines as $item) {
            $this->inventory->apply(
                StockMovementDraft::in(
                    $item->product_id,
                    $purchase->warehouse_id,
                    (string) $item->quantity,
                    $item->subtotalAmount(),
                    MovementType::Purchase,
                    $purchase->date,
                )->fromDocument(Purchase::SOURCE_TYPE, $purchase->id, $purchase->number)
                    ->describedAs($purchase->supplier->name)
            );
        }
    }

    /**
     * Devuelve al proveedor lo que la compra había ingresado.
     *
     * Sale por el **valor original**, no por el promedio de hoy: la partida
     * contable que se acaba de revertir movió ese importe, y el kardex tiene
     * que mover el mismo. Además es lo correcto: si después de esta compra
     * entró otra más cara, lo que queda en bodega es la mercadería cara, y su
     * promedio debe reflejarlo.
     *
     * Por eso también se exige que la mercadería siga en bodega. Si ya se
     * vendió, no hay nada que devolver y la corrección no es anular la compra
     * sino registrar una devolución al proveedor.
     */
    private function returnFromStock(Purchase $purchase): void
    {
        $purchase->loadMissing('items.product');

        $lines = $purchase->items->filter(fn (PurchaseItem $item) => $this->entersInventory($item));

        if ($lines->isEmpty() || $purchase->warehouse_id === null) {
            return;
        }

        foreach ($lines as $item) {
            $this->inventory->apply(
                StockMovementDraft::out(
                    $item->product_id,
                    $purchase->warehouse_id,
                    (string) $item->quantity,
                    MovementType::PurchaseVoid,
                    now(),
                )->valuedAt($item->subtotalAmount())
                    ->fromDocument(Purchase::SOURCE_TYPE, $purchase->id, $purchase->number)
                    ->describedAs('Anulación de compra')
            );
        }
    }

    /**
     * @return array<int, Money>
     */
    private function taxByAccount(Purchase $purchase): array
    {
        $default = $this->mappings->resolveId(AccountMappingKey::PurchasesTaxCredit);
        $totals = [];

        foreach ($purchase->items as $item) {
            if ($item->taxAmount()->isZero()) {
                continue;
            }

            // La cuenta de crédito fiscal del impuesto, no la de débito: aquí
            // el impuesto es un derecho a favor, no una deuda.
            $accountId = $item->tax?->creditable_account_id ?? $default;
            $totals[$accountId] = ($totals[$accountId] ?? Money::zero())->plus($item->taxAmount());
        }

        return $totals;
    }

    private function voidOrReverse(JournalEntry $entry, string $reason): void
    {
        if ($entry->period->acceptsPostings()) {
            $this->engine->void($entry, $reason);

            return;
        }

        $this->engine->reverse($entry, $reason);
    }

    /**
     * El índice único de la base de datos es la garantía real; esta consulta
     * existe para dar un mensaje claro en vez de un error de restricción.
     */
    private function guardInvoiceNotDuplicated(Purchase $purchase): void
    {
        $exists = Purchase::query()
            ->where('supplier_id', $purchase->supplier_id)
            ->where('supplier_invoice_number', $purchase->supplier_invoice_number)
            ->where('status', PurchaseStatus::Received)
            ->whereKeyNot($purchase->id)
            ->exists();

        if ($exists) {
            throw PurchaseException::duplicateInvoice(
                $purchase->supplier,
                $purchase->supplier_invoice_number,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function headerAttributes(array $data): array
    {
        $condition = $data['payment_condition'] instanceof PaymentCondition
            ? $data['payment_condition']
            : PaymentCondition::from((string) ($data['payment_condition'] ?? 'credit'));

        return [
            'branch_id' => $data['branch_id'],
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'supplier_id' => $data['supplier_id'],
            'supplier_invoice_number' => trim((string) ($data['supplier_invoice_number'] ?? '')),
            'date' => CarbonImmutable::parse($data['date'])->toDateString(),
            'payment_condition' => $condition,
            'credit_days' => $condition->createsReceivable() ? (int) ($data['credit_days'] ?? 0) : 0,
            'payment_account_id' => $condition->createsReceivable() ? null : ($data['payment_account_id'] ?? null),
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function replaceLines(Purchase $purchase, array $lines): void
    {
        $purchase->items()->delete();

        $number = 1;

        foreach ($lines as $line) {
            $product = isset($line['product_id'])
                ? Product::query()->find($line['product_id'])
                : null;

            $tax = isset($line['tax_id'])
                ? Tax::query()->find($line['tax_id'])
                : $product?->tax;

            $item = new PurchaseItem;
            $item->forceFill([
                'purchase_id' => $purchase->id,
                'company_id' => $purchase->company_id,
                'product_id' => $product?->id,
                'line_number' => $number++,
                'description' => $line['description'] ?? $product?->name ?? '',
                'quantity' => (string) $line['quantity'],
                'unit_price' => (string) $line['unit_price'],
                'discount_rate' => (string) ($line['discount_rate'] ?? '0'),
                'tax_id' => $tax?->id,
                'tax_rate' => $tax?->rate ?? '0',
                'expense_account_id' => $line['expense_account_id'] ?? null,
                'discount_amount' => '0',
                'tax_amount' => '0',
                'subtotal' => '0',
                'total' => '0',
            ])->save();
        }

        $this->recalculate($purchase->refresh());
    }
}
