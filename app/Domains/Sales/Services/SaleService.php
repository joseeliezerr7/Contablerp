<?php

declare(strict_types=1);

namespace App\Domains\Sales\Services;

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Enums\AccountMappingKey;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Accounting\Services\AccountMappingService;
use App\Domains\Catalog\Models\Product;
use App\Domains\Fiscal\Enums\FiscalDocumentType;
use App\Domains\Fiscal\Services\FiscalNumberService;
use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Inventory\DataTransfer\StockMovementDraft;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Services\InventoryService;
use App\Domains\Partners\Models\Customer;
use App\Domains\Receivables\Services\ReceivableService;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SaleItem;
use App\Domains\Sales\Models\SalePayment;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Taxation\Services\TaxResolver;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Emisión y anulación de facturas de venta.
 *
 * Una factura emitida produce, en la misma transacción: su correlativo, la
 * cuenta por cobrar si es al crédito, y la partida contable. Si algo falla, no
 * queda nada a medias.
 *
 * El costo de ventas va en la **misma partida** que la factura, no en una
 * aparte. El motor contable garantiza la idempotencia con un índice único sobre
 * (source_type, source_id): dos partidas por documento obligarían a inventar
 * dos claves y a coordinar dos anulaciones. Las líneas de costo las alimenta el
 * kardex al descargar la mercadería, justo antes de armar la partida.
 */
final class SaleService
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly TaxResolver $taxes,
        private readonly AccountingEngine $engine,
        private readonly AccountMappingService $mappings,
        private readonly FiscalNumberService $fiscal,
        private readonly ReceivableService $receivables,
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Guarda la factura como borrador. No numera, no contabiliza y no afecta
     * la cuenta corriente del cliente.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function saveDraft(array $data, array $lines): Sale
    {
        return DB::transaction(function () use ($data, $lines): Sale {
            $sale = new Sale;
            $sale->forceFill([
                ...$this->headerAttributes($data),
                'company_id' => $this->context->idOrFail(),
                'status' => SaleStatus::Draft,
                'created_by' => Auth::id(),
            ])->save();

            $this->replaceLines($sale, $lines);

            return $sale->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function updateDraft(Sale $sale, array $data, array $lines): Sale
    {
        if (! $sale->isDraft()) {
            throw SalesException::notDraft($sale);
        }

        return DB::transaction(function () use ($sale, $data, $lines): Sale {
            $sale->forceFill($this->headerAttributes($data))->save();
            $this->replaceLines($sale, $lines);

            return $sale->refresh();
        });
    }

    public function deleteDraft(Sale $sale): void
    {
        if (! $sale->isDraft()) {
            throw SalesException::notDraft($sale);
        }

        DB::transaction(function () use ($sale): void {
            $sale->items()->delete();
            $sale->delete();
        });
    }

    /**
     * Emite la factura: correlativo, cuenta por cobrar y partida contable.
     *
     * @param  array<int, array<string, mixed>>  $payments  Cobros aplicados en
     *                                                      el acto (mostrador). Vacío en las ventas al crédito.
     */
    public function issue(Sale $sale, bool $overrideCreditLimit = false, array $payments = []): Sale
    {
        if ($sale->isIssued()) {
            throw SalesException::alreadyIssued($sale);
        }

        if (! $sale->isDraft()) {
            throw SalesException::notDraft($sale);
        }

        return DB::transaction(function () use ($sale, $overrideCreditLimit, $payments): Sale {
            $sale->load(['items.product', 'items.tax', 'customer', 'branch']);

            if ($sale->items->isEmpty()) {
                throw SalesException::noLines();
            }

            $customer = $sale->customer;

            if (! $customer->is_active) {
                throw SalesException::inactiveCustomer($customer);
            }

            // Los totales se recalculan siempre: los guardados en el borrador
            // pudieron quedar obsoletos si cambió un precio o un impuesto.
            $this->recalculate($sale);

            // Se recargan las líneas con producto e impuesto: la partida los
            // necesita para resolver las cuentas de cada línea.
            $sale->refresh()->load(['items.product', 'items.tax']);

            if ($sale->isOnCredit()) {
                $this->guardCreditLimit($customer, $sale->totalAmount(), $overrideCreditLimit);
            } elseif ($payments === [] && $sale->deposit_account_id === null) {
                throw SalesException::missingDepositAccount();
            }

            // Los cobros se guardan antes de armar la partida: es de ellos de
            // donde salen las cuentas que se debitan.
            if ($payments !== []) {
                $this->replacePayments($sale, $payments);
            }

            // El número sale de la autorización del SAR, no de una serie
            // interna: es lo que convierte el documento en una factura. Si no
            // hay CAI vigente, o se agotó el rango, o venció la fecha límite,
            // esto lanza y la factura no se emite.
            $fiscal = $this->fiscal->reserve(
                $sale->branch,
                FiscalDocumentType::Invoice,
                $sale->date,
            );

            $dueDate = $sale->isOnCredit()
                ? CarbonImmutable::parse($sale->date)->addDays($sale->credit_days)
                : CarbonImmutable::parse($sale->date);

            $sale->forceFill([
                // El CAI, el rango y la fecha límite quedan congelados en el
                // documento: una reimpresión dentro de tres años tiene que dar
                // el mismo papel que se entregó hoy.
                ...$fiscal->documentAttributes(),
                'due_date' => $dueDate->toDateString(),
                'status' => SaleStatus::Issued,
                'issued_at' => now(),
                'issued_by' => Auth::id(),
            ])->save();

            if ($sale->isOnCredit()) {
                $this->receivables->openFor($sale);
            }

            // El inventario se descarga **antes** de armar la partida: es la
            // descarga la que fija el costo de cada línea, y la partida lo lee
            // de ahí para asentar el costo de ventas. Al revés, el costo sería
            // siempre el de la factura anterior.
            $this->issueFromStock($sale);

            // Se recargan ambas relaciones: `load()` reemplaza la colección de
            // líneas, y quedarse solo con el producto dejaría al impuesto sin
            // cargar justo antes de armar la partida.
            $sale->load(['items.product', 'items.tax', 'payments']);

            $this->engine->post($this->buildJournalDraft($sale));

            $this->audit->log('issued', $sale, newValues: [
                'number' => $sale->number,
                'total' => $sale->total,
            ], module: 'sales');

            return $sale->refresh();
        });
    }

    /**
     * Crea y emite en un solo paso, que es lo que hace el mostrador.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function createAndIssue(
        array $data,
        array $lines,
        bool $overrideCreditLimit = false,
        array $payments = [],
    ): Sale {
        return DB::transaction(function () use ($data, $lines, $overrideCreditLimit, $payments): Sale {
            $sale = $this->saveDraft($data, $lines);

            return $this->issue($sale, $overrideCreditLimit, $payments);
        });
    }

    /**
     * Anula la factura: revierte la partida, cancela la cuenta por cobrar y
     * conserva el documento con su número y sus líneas.
     */
    public function void(Sale $sale, string $reason): Sale
    {
        if ($sale->isVoided()) {
            throw SalesException::alreadyVoided($sale);
        }

        if (! $sale->isIssued()) {
            throw SalesException::notIssued($sale);
        }

        if (trim($reason) === '') {
            throw SalesException::emptyReason();
        }

        return DB::transaction(function () use ($sale, $reason): Sale {
            $receivable = $sale->receivable;

            // Una factura con abonos no puede anularse: primero hay que anular
            // los recibos, o el dinero cobrado quedaría sin documento.
            if ($receivable !== null && $receivable->paidAmount()->isPositive()) {
                throw SalesException::hasPayments($sale);
            }

            $entry = $sale->journalEntry();

            if ($entry !== null) {
                $this->voidOrReverse($entry, $reason);
            }

            $this->returnToStock($sale);

            if ($receivable !== null) {
                $this->receivables->cancel($receivable);
            }

            $sale->forceFill([
                'status' => SaleStatus::Voided,
                'voided_at' => now(),
                'voided_by' => Auth::id(),
                'void_reason' => $reason,
            ])->save();

            $this->audit->log('voided', $sale, oldValues: ['status' => SaleStatus::Issued->value],
                newValues: ['status' => SaleStatus::Voided->value], reason: $reason, module: 'sales');

            return $sale->refresh();
        });
    }

    /**
     * Recalcula importes de las líneas y totales de la cabecera.
     */
    public function recalculate(Sale $sale): Sale
    {
        $sale->loadMissing('items.tax');

        $calculations = [];

        foreach ($sale->items as $item) {
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

        $sale->forceFill([
            'subtotal' => $totals['subtotal']->toString(),
            'discount_total' => $totals['discount']->toString(),
            'tax_total' => $totals['tax']->toString(),
            'total' => $totals['total']->toString(),
        ])->save();

        return $sale;
    }

    /**
     * Partida contable de la factura.
     *
     * Los ingresos se acreditan por su importe **bruto** y el descuento se
     * carga aparte, para que el estado de resultados muestre la venta y la
     * rebaja por separado en vez de una cifra ya neteada.
     */
    private function buildJournalDraft(Sale $sale): JournalDraft
    {
        $concept = sprintf('Factura %s — %s', $sale->number, $sale->customer->name);

        $draft = JournalDraft::on($sale->date, $concept)
            ->inBranch($sale->branch_id)
            ->withReference($sale->number)
            ->fromDocument(Sale::SOURCE_TYPE, $sale->id);

        // Lado deudor: el cliente si es crédito, y si es de contado, cada
        // cuenta donde entró el dinero. Con pago dividido son varias —efectivo
        // en la caja, tarjeta en el banco— y cada una tiene que recibir lo suyo
        // o la conciliación bancaria dejaría de casar.
        if ($sale->isOnCredit()) {
            $draft->debit(
                $this->mappings->resolveId(AccountMappingKey::SalesReceivable),
                $sale->totalAmount(),
                "Factura {$sale->number}",
            );
        } elseif ($sale->payments->isNotEmpty()) {
            foreach ($this->paymentsByAccount($sale) as $accountId => $amount) {
                $draft->debit($accountId, $amount, "Factura {$sale->number} de contado");
            }
        } else {
            $draft->debit(
                $sale->deposit_account_id,
                $sale->totalAmount(),
                "Factura {$sale->number} de contado",
            );
        }

        if ($sale->discountAmount()->isPositive()) {
            $draft->debit(
                $this->mappings->resolveId(AccountMappingKey::SalesDiscount),
                $sale->discountAmount(),
                'Descuentos concedidos',
            );
        }

        foreach ($this->revenueByAccount($sale) as $accountId => $amount) {
            $draft->credit($accountId, $amount, 'Ventas');
        }

        foreach ($this->taxByAccount($sale) as $accountId => $amount) {
            $draft->credit($accountId, $amount, 'Impuesto sobre ventas');
        }

        $this->appendCostOfSales($draft, $sale);

        return $draft;
    }

    /**
     * Ingreso bruto agrupado por cuenta: cada producto puede llevar su propia
     * cuenta de ingresos, y si no la tiene se usa la del mapeo general.
     *
     * @return array<int, Money>
     */
    private function revenueByAccount(Sale $sale): array
    {
        $default = $this->mappings->resolveId(AccountMappingKey::SalesRevenue);
        $totals = [];

        foreach ($sale->items as $item) {
            $accountId = $item->product?->income_account_id ?? $default;
            $gross = $item->subtotalAmount()->plus($item->discountAmount());

            $totals[$accountId] = ($totals[$accountId] ?? Money::zero())->plus($gross);
        }

        return array_filter($totals, fn (Money $amount) => $amount->isPositive());
    }

    /**
     * Cobros agrupados por cuenta. Dos pagos con tarjeta al mismo banco son una
     * sola línea en la partida.
     *
     * @return array<int, Money>
     */
    private function paymentsByAccount(Sale $sale): array
    {
        $totals = [];

        foreach ($sale->payments as $payment) {
            $totals[$payment->account_id] = ($totals[$payment->account_id] ?? Money::zero())
                ->plus($payment->amountMoney());
        }

        return $totals;
    }

    /**
     * @return array<int, Money>
     */
    private function taxByAccount(Sale $sale): array
    {
        $default = $this->mappings->resolveId(AccountMappingKey::SalesTaxPayable);
        $totals = [];

        foreach ($sale->items as $item) {
            if ($item->taxAmount()->isZero()) {
                continue;
            }

            $accountId = $item->tax?->payable_account_id ?? $default;
            $totals[$accountId] = ($totals[$accountId] ?? Money::zero())->plus($item->taxAmount());
        }

        return $totals;
    }

    /**
     * Añade el costo de ventas a la misma partida.
     *
     * Los importes salen de `cost_total`, que es lo que el kardex descargó un
     * momento antes. Una línea sin costo —un servicio, un producto sin control
     * de existencias— no agrega nada.
     */
    private function appendCostOfSales(JournalDraft $draft, Sale $sale): void
    {
        $costByAccount = [];
        $inventoryByAccount = [];

        $defaultCost = null;
        $defaultInventory = null;

        foreach ($sale->items as $item) {
            $cost = $item->costAmount();

            if (! $cost->isPositive()) {
                continue;
            }

            $defaultCost ??= $this->mappings->resolveId(AccountMappingKey::SalesCostOfGoods);
            $defaultInventory ??= $this->mappings->resolveId(AccountMappingKey::InventoryAsset);

            $costAccount = $item->product?->cost_account_id ?? $defaultCost;
            $inventoryAccount = $item->product?->inventory_account_id ?? $defaultInventory;

            $costByAccount[$costAccount] = ($costByAccount[$costAccount] ?? Money::zero())->plus($cost);
            $inventoryByAccount[$inventoryAccount] = ($inventoryByAccount[$inventoryAccount] ?? Money::zero())->plus($cost);
        }

        foreach ($costByAccount as $accountId => $amount) {
            $draft->debit($accountId, $amount, 'Costo de ventas');
        }

        foreach ($inventoryByAccount as $accountId => $amount) {
            $draft->credit($accountId, $amount, 'Salida de inventario');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Inventario
    |--------------------------------------------------------------------------
    */

    /**
     * Descarga la mercadería y anota en cada línea el costo exacto que salió.
     *
     * Si no hay existencia suficiente, `InsufficientStockException` sube y
     * revierte la transacción entera: la factura no se emite. Es una decisión
     * tomada al diseñar el sistema (D-04) y no tiene interruptor —permitir
     * existencias negativas deja el costo promedio sin significado, y con él el
     * costo de ventas y la utilidad—.
     */
    private function issueFromStock(Sale $sale): void
    {
        $lines = $sale->items->filter(
            fn (SaleItem $item) => $item->product?->track_inventory === true
        );

        if ($lines->isEmpty()) {
            return;
        }

        if ($sale->warehouse_id === null) {
            throw SalesException::missingWarehouse();
        }

        foreach ($lines as $item) {
            $movement = $this->inventory->apply(
                StockMovementDraft::out(
                    $item->product_id,
                    $sale->warehouse_id,
                    (string) $item->quantity,
                    MovementType::Sale,
                    $sale->date,
                )->fromDocument(Sale::SOURCE_TYPE, $sale->id, $sale->number)
                    ->describedAs($sale->customer->name)
            );

            $cost = $movement->valueAmount()->absolute();

            $item->forceFill([
                'unit_cost' => $movement->unit_cost,
                'cost_total' => $cost->toString(),
            ])->save();
        }
    }

    /**
     * Reingresa la mercadería de una factura anulada.
     *
     * Vuelve por el **mismo importe** que salió, no por el promedio de hoy: la
     * partida contable que se acaba de revertir acreditó ese costo, y el kardex
     * tiene que devolver el mismo número a la cuenta de inventario.
     */
    private function returnToStock(Sale $sale): void
    {
        $sale->loadMissing('items.product');

        $lines = $sale->items->filter(
            fn (SaleItem $item) => $item->costAmount()->isPositive()
        );

        if ($lines->isEmpty() || $sale->warehouse_id === null) {
            return;
        }

        foreach ($lines as $item) {
            $this->inventory->apply(
                StockMovementDraft::in(
                    $item->product_id,
                    $sale->warehouse_id,
                    (string) $item->quantity,
                    $item->costAmount(),
                    MovementType::SaleVoid,
                    now(),
                )->fromDocument(Sale::SOURCE_TYPE, $sale->id, $sale->number)
                    ->describedAs('Anulación de factura')
            );
        }
    }

    /**
     * Anula la partida si su período sigue abierto; si ya se cerró, genera una
     * reversión, que es la única corrección válida sobre un período cerrado.
     */
    private function voidOrReverse(JournalEntry $entry, string $reason): void
    {
        if ($entry->period->acceptsPostings()) {
            $this->engine->void($entry, $reason);

            return;
        }

        $this->engine->reverse($entry, $reason);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function headerAttributes(array $data): array
    {
        $condition = $data['payment_condition'] instanceof PaymentCondition
            ? $data['payment_condition']
            : PaymentCondition::from((string) ($data['payment_condition'] ?? 'cash'));

        return [
            'branch_id' => $data['branch_id'],
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'customer_id' => $data['customer_id'],
            'date' => CarbonImmutable::parse($data['date'])->toDateString(),
            'payment_condition' => $condition,
            'credit_days' => $condition->createsReceivable() ? (int) ($data['credit_days'] ?? 0) : 0,
            'deposit_account_id' => $condition->createsReceivable() ? null : ($data['deposit_account_id'] ?? null),
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function replaceLines(Sale $sale, array $lines): void
    {
        $sale->items()->delete();

        $number = 1;

        foreach ($lines as $line) {
            $product = isset($line['product_id'])
                ? Product::query()->find($line['product_id'])
                : null;

            $tax = isset($line['tax_id'])
                ? Tax::query()->find($line['tax_id'])
                : $product?->tax;

            $item = new SaleItem;
            $item->forceFill([
                'sale_id' => $sale->id,
                'company_id' => $sale->company_id,
                'product_id' => $product?->id,
                'line_number' => $number++,
                // La descripción se congela en la línea: renombrar el producto
                // después no debe alterar una factura ya emitida.
                'description' => $line['description'] ?? $product?->name ?? '',
                'quantity' => (string) $line['quantity'],
                'unit_price' => (string) $line['unit_price'],
                'discount_rate' => (string) ($line['discount_rate'] ?? '0'),
                'tax_id' => $tax?->id,
                'tax_rate' => $tax?->rate ?? '0',
                // El costo lo pone el kardex al emitir, no el catálogo: el
                // costo de referencia del producto es una estimación, y lo que
                // debe llegar al estado de resultados es lo que de verdad salió
                // de la bodega.
                'unit_cost' => '0',
                'cost_total' => '0',
                'discount_amount' => '0',
                'tax_amount' => '0',
                'subtotal' => '0',
                'total' => '0',
            ])->save();
        }

        $this->recalculate($sale->refresh());
    }

    /**
     * Guarda los cobros de la factura.
     *
     * La suma tiene que ser **exactamente** el total. Un cobro de menos dejaría
     * una factura de contado a medias, sin cuenta por cobrar que la persiga; uno
     * de más descuadraría la partida. El vuelto no cuenta: sale de lo entregado,
     * no del importe cobrado.
     *
     * @param  array<int, array<string, mixed>>  $payments
     */
    private function replacePayments(Sale $sale, array $payments): void
    {
        $sale->payments()->delete();

        $total = Money::zero();

        foreach ($payments as $payment) {
            $amount = Money::of((string) $payment['amount']);

            if (! $amount->isPositive()) {
                throw SalesException::nonPositivePayment();
            }

            $method = $payment['method'] instanceof PaymentMethod
                ? $payment['method']
                : PaymentMethod::from((string) $payment['method']);

            $reference = isset($payment['reference']) ? trim((string) $payment['reference']) : null;

            if ($method->requiresReference() && ($reference === null || $reference === '')) {
                throw SalesException::paymentNeedsReference($method);
            }

            $item = new SalePayment;
            $item->forceFill([
                'company_id' => $sale->company_id,
                'sale_id' => $sale->id,
                'method' => $method,
                'account_id' => $payment['account_id'],
                'amount' => $amount->toString(),
                'tendered' => isset($payment['tendered']) ? Money::of((string) $payment['tendered'])->toString() : null,
                'change_given' => isset($payment['change_given']) ? Money::of((string) $payment['change_given'])->toString() : null,
                'reference' => $reference ?: null,
            ])->save();

            $total = $total->plus($amount);
        }

        if (! $total->equals($sale->totalAmount())) {
            throw SalesException::paymentsDoNotMatchTotal($sale, $total);
        }
    }

    private function guardCreditLimit(Customer $customer, Money $total, bool $override): void
    {
        if ($override) {
            return;
        }

        if (! $customer->hasCredit()) {
            throw SalesException::noCreditTerms($customer);
        }

        $balance = $customer->outstandingBalance();

        if ($balance->plus($total)->greaterThan($customer->creditLimit())) {
            throw SalesException::creditLimitExceeded($customer, $balance, $total);
        }
    }
}
