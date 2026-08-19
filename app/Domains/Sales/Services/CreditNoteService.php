<?php

declare(strict_types=1);

namespace App\Domains\Sales\Services;

use App\Domains\Accounting\DataTransfer\JournalDraft;
use App\Domains\Accounting\Enums\AccountMappingKey;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Accounting\Services\AccountMappingService;
use App\Domains\Fiscal\Enums\FiscalDocumentType;
use App\Domains\Fiscal\Services\FiscalNumberService;
use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Inventory\DataTransfer\StockMovementDraft;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Services\InventoryService;
use App\Domains\Receivables\Services\ReceivableService;
use App\Domains\Sales\Enums\CreditNoteReason;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\CreditNote;
use App\Domains\Sales\Models\CreditNoteItem;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SaleItem;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Taxation\Services\TaxResolver;
use App\Support\Money;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Notas de crédito sobre facturas emitidas.
 *
 * ## Por qué existe, si ya se puede anular una factura
 *
 * Anular borra el documento del mundo: sirve el mismo día, antes de que el
 * cliente se haya llevado el papel. La nota de crédito es lo que se usa cuando
 * la factura ya circuló —el cliente la tiene, la declaró, quizá ya la pagó a
 * medias— y hay que rebajarla **sin borrarla**. Las dos operaciones dejan
 * rastros distintos y el fisco espera cada una en su sitio.
 *
 * ## La partida
 *
 * Es la inversa de la factura, pero **no** una reversión: se acredita contra
 * «Devoluciones sobre ventas», no contra la cuenta de ingresos. Restar del
 * ingreso bruto escondería la devolución; el estado de resultados debe mostrar
 * lo que se vendió y lo que se devolvió por separado, que es lo que permite
 * notar que un producto vuelve demasiado.
 *
 * El impuesto sí se carga directamente contra la cuenta por pagar del impuesto:
 * lo que se le devuelve al cliente incluye el ISV, y ese ISV deja de deberse.
 */
final class CreditNoteService
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
     * Guarda la nota como borrador. No numera, no contabiliza y no toca el saldo
     * del cliente.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function saveDraft(Sale $sale, array $data, array $lines): CreditNote
    {
        $this->guardCreditable($sale);

        return DB::transaction(function () use ($sale, $data, $lines): CreditNote {
            $reason = $this->reasonFrom($data);

            $note = new CreditNote;
            $note->forceFill([
                'company_id' => $this->context->idOrFail(),
                'branch_id' => $sale->branch_id,
                'customer_id' => $sale->customer_id,
                'sale_id' => $sale->id,
                'date' => CarbonImmutable::parse($data['date'] ?? now())->toDateString(),
                'reason' => $reason,
                'description' => trim((string) $data['description']),
                // La mercadería solo vuelve si el motivo es una devolución. Un
                // descuento posterior mueve dinero, no cajas.
                'restocks' => $reason->movesStock() && ($data['restocks'] ?? true),
                'warehouse_id' => $data['warehouse_id'] ?? $sale->warehouse_id,
                'currency_code' => $sale->currency_code,
                'exchange_rate' => $sale->exchange_rate,
                'status' => SaleStatus::Draft,
                'created_by' => Auth::id(),
            ])->save();

            $this->replaceLines($note, $sale, $lines);

            return $note->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function updateDraft(CreditNote $note, array $data, array $lines): CreditNote
    {
        if (! $note->isDraft()) {
            throw SalesException::creditNoteNotDraft($note);
        }

        return DB::transaction(function () use ($note, $data, $lines): CreditNote {
            $reason = $this->reasonFrom($data);

            $note->forceFill([
                'date' => CarbonImmutable::parse($data['date'] ?? $note->date)->toDateString(),
                'reason' => $reason,
                'description' => trim((string) $data['description']),
                'restocks' => $reason->movesStock() && ($data['restocks'] ?? true),
                'warehouse_id' => $data['warehouse_id'] ?? $note->warehouse_id,
            ])->save();

            $this->replaceLines($note, $note->sale, $lines);

            return $note->refresh();
        });
    }

    public function deleteDraft(CreditNote $note): void
    {
        if (! $note->isDraft()) {
            throw SalesException::creditNoteNotDraft($note);
        }

        DB::transaction(function () use ($note): void {
            $note->items()->delete();
            $note->delete();
        });
    }

    /**
     * Emite la nota: correlativo fiscal propio, partida contable, rebaja de la
     * cuenta por cobrar y —si es devolución— reingreso de la mercadería.
     */
    public function issue(CreditNote $note): CreditNote
    {
        if ($note->isIssued()) {
            throw SalesException::creditNoteAlreadyIssued($note);
        }

        if (! $note->isDraft()) {
            throw SalesException::creditNoteNotDraft($note);
        }

        return DB::transaction(function () use ($note): CreditNote {
            $note->load(['items.product', 'items.tax', 'items.saleItem', 'sale', 'customer', 'branch']);

            if ($note->items->isEmpty()) {
                throw SalesException::noLines();
            }

            $this->guardCreditable($note->sale);
            $this->recalculate($note);
            $note->refresh()->load(['items.product', 'items.tax', 'items.saleItem', 'sale']);

            $this->guardNotOverCrediting($note);

            $fiscal = $this->fiscal->reserve(
                $note->branch,
                FiscalDocumentType::CreditNote,
                $note->date,
            );

            $note->forceFill([
                ...$fiscal->documentAttributes(),
                'status' => SaleStatus::Issued,
                'issued_at' => now(),
                'issued_by' => Auth::id(),
            ])->save();

            // El inventario se mueve antes de armar la partida, igual que en la
            // factura: es la entrada la que fija el costo que se contabiliza.
            if ($note->restocks) {
                $this->returnToStock($note);
                $note->load('items.product');
            }

            $this->applyToReceivable($note);

            $this->engine->post($this->buildJournalDraft($note));

            $this->audit->log('issued', $note, newValues: [
                'number' => $note->number,
                'total' => $note->total,
                'sale' => $note->sale->number,
            ], module: 'sales');

            return $note->refresh();
        });
    }

    /**
     * Anula la nota: revierte la partida, devuelve el saldo a la cuenta por
     * cobrar y saca de nuevo la mercadería que había reingresado.
     */
    public function void(CreditNote $note, string $reason): CreditNote
    {
        if ($note->isVoided()) {
            throw SalesException::creditNoteAlreadyVoided($note);
        }

        if (! $note->isIssued()) {
            throw SalesException::creditNoteNotIssued($note);
        }

        if (trim($reason) === '') {
            throw SalesException::emptyReason();
        }

        return DB::transaction(function () use ($note, $reason): CreditNote {
            $entry = $note->journalEntry();

            if ($entry !== null) {
                $this->voidOrReverse($entry, $reason);
            }

            if ($note->restocks) {
                $this->takeBackFromStock($note);
            }

            $receivable = $note->sale->receivable;

            if ($receivable !== null) {
                $this->receivables->reverseCredit($receivable, $note->totalAmount());
            }

            $note->forceFill([
                'status' => SaleStatus::Voided,
                'voided_at' => now(),
                'voided_by' => Auth::id(),
                'void_reason' => $reason,
            ])->save();

            $this->audit->log('voided', $note, newValues: ['status' => SaleStatus::Voided->value],
                reason: $reason, module: 'sales');

            return $note->refresh();
        });
    }

    /**
     * Recalcula importes de las líneas y totales de la cabecera.
     */
    public function recalculate(CreditNote $note): CreditNote
    {
        $note->loadMissing('items.tax');

        $calculations = [];

        foreach ($note->items as $item) {
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

        $note->forceFill([
            'subtotal' => $totals['subtotal']->toString(),
            'discount_total' => $totals['discount']->toString(),
            'tax_total' => $totals['tax']->toString(),
            'total' => $totals['total']->toString(),
        ])->save();

        return $note;
    }

    /*
    |--------------------------------------------------------------------------
    | Contabilidad
    |--------------------------------------------------------------------------
    */

    private function buildJournalDraft(CreditNote $note): JournalDraft
    {
        $concept = sprintf(
            'Nota de crédito %s sobre factura %s — %s',
            $note->number,
            $note->sale->number,
            $note->customer->name,
        );

        $draft = JournalDraft::on($note->date, $concept)
            ->inBranch($note->branch_id)
            ->withReference($note->number)
            ->fromDocument(CreditNote::SOURCE_TYPE, $note->id);

        // Débito: la devolución, contra su propia cuenta y no contra el ingreso.
        $draft->debit(
            $this->mappings->resolveId(AccountMappingKey::SalesReturns),
            $note->subtotalAmount()->plus($note->discountAmount()),
            'Devoluciones sobre ventas',
        );

        // Débito: el impuesto que deja de deberse.
        foreach ($this->taxByAccount($note) as $accountId => $amount) {
            $draft->debit($accountId, $amount, 'Impuesto sobre ventas devuelto');
        }

        // Si la factura llevaba descuento y la nota también, el descuento
        // acreditado vuelve a su cuenta.
        if ($note->discountAmount()->isPositive()) {
            $draft->credit(
                $this->mappings->resolveId(AccountMappingKey::SalesDiscount),
                $note->discountAmount(),
                'Descuentos concedidos revertidos',
            );
        }

        // Crédito: se le rebaja al cliente lo que debía, o se le devuelve el
        // dinero si la factura fue de contado.
        $draft->credit(
            $this->creditSideAccount($note),
            $note->totalAmount(),
            $note->sale->isOnCredit()
                ? "Rebaja sobre factura {$note->sale->number}"
                : "Devolución al cliente por factura {$note->sale->number}",
        );

        $this->appendCostReturn($draft, $note);

        return $draft;
    }

    /**
     * Contra qué cuenta se acredita.
     *
     * En una factura al crédito, contra la cuenta por cobrar: el cliente debe
     * menos. En una de contado, contra la misma caja o banco donde entró el
     * dinero, porque es de ahí de donde sale la devolución.
     */
    private function creditSideAccount(CreditNote $note): int
    {
        if ($note->sale->isOnCredit()) {
            return $this->mappings->resolveId(AccountMappingKey::SalesReceivable);
        }

        return $note->sale->deposit_account_id
            ?? $this->mappings->resolveId(AccountMappingKey::TreasuryCash);
    }

    /**
     * @return array<int, Money>
     */
    private function taxByAccount(CreditNote $note): array
    {
        $default = $this->mappings->resolveId(AccountMappingKey::SalesTaxPayable);
        $totals = [];

        foreach ($note->items as $item) {
            if ($item->taxAmount()->isZero()) {
                continue;
            }

            $accountId = $item->tax?->payable_account_id ?? $default;
            $totals[$accountId] = ($totals[$accountId] ?? Money::zero())->plus($item->taxAmount());
        }

        return $totals;
    }

    /**
     * Devuelve el costo al inventario y lo saca del costo de ventas.
     *
     * Es la inversa exacta de lo que hizo la factura, y por el mismo importe:
     * la mercadería salió valorada a aquel costo y tiene que volver igual, o el
     * kardex y la cuenta contable dejarían de cuadrar.
     */
    private function appendCostReturn(JournalDraft $draft, CreditNote $note): void
    {
        if (! $note->restocks) {
            return;
        }

        $inventoryByAccount = [];
        $costByAccount = [];

        $defaultCost = null;
        $defaultInventory = null;

        foreach ($note->items as $item) {
            $cost = $item->costAmount();

            if (! $cost->isPositive()) {
                continue;
            }

            $defaultCost ??= $this->mappings->resolveId(AccountMappingKey::SalesCostOfGoods);
            $defaultInventory ??= $this->mappings->resolveId(AccountMappingKey::InventoryAsset);

            $costAccount = $item->product?->cost_account_id ?? $defaultCost;
            $inventoryAccount = $item->product?->inventory_account_id ?? $defaultInventory;

            $inventoryByAccount[$inventoryAccount] = ($inventoryByAccount[$inventoryAccount] ?? Money::zero())->plus($cost);
            $costByAccount[$costAccount] = ($costByAccount[$costAccount] ?? Money::zero())->plus($cost);
        }

        foreach ($inventoryByAccount as $accountId => $amount) {
            $draft->debit($accountId, $amount, 'Reingreso de inventario');
        }

        foreach ($costByAccount as $accountId => $amount) {
            $draft->credit($accountId, $amount, 'Costo de ventas revertido');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Cuenta por cobrar e inventario
    |--------------------------------------------------------------------------
    */

    private function applyToReceivable(CreditNote $note): void
    {
        $receivable = $note->sale->receivable;

        if ($receivable === null) {
            return;
        }

        $this->receivables->applyCredit($receivable, $note->totalAmount());
    }

    private function returnToStock(CreditNote $note): void
    {
        $lines = $note->items->filter(
            fn (CreditNoteItem $item) => $item->product?->track_inventory === true
                && $item->costAmount()->isPositive()
        );

        if ($lines->isEmpty()) {
            return;
        }

        if ($note->warehouse_id === null) {
            throw SalesException::missingWarehouse();
        }

        foreach ($lines as $item) {
            $this->inventory->apply(
                StockMovementDraft::in(
                    $item->product_id,
                    $note->warehouse_id,
                    (string) $item->quantity,
                    $item->costAmount(),
                    MovementType::SaleReturn,
                    $note->date,
                )->fromDocument(CreditNote::SOURCE_TYPE, $note->id, $note->number)
                    ->describedAs('Devolución de '.$note->customer->name)
            );
        }
    }

    private function takeBackFromStock(CreditNote $note): void
    {
        $note->loadMissing('items.product');

        $lines = $note->items->filter(
            fn (CreditNoteItem $item) => $item->product?->track_inventory === true
                && $item->costAmount()->isPositive()
        );

        foreach ($lines as $item) {
            $this->inventory->apply(
                StockMovementDraft::out(
                    $item->product_id,
                    $note->warehouse_id,
                    (string) $item->quantity,
                    MovementType::SaleReturnVoid,
                    now(),
                )->fromDocument(CreditNote::SOURCE_TYPE, $note->id, $note->number)
                    ->describedAs('Anulación de nota de crédito')
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Guardas
    |--------------------------------------------------------------------------
    */

    /**
     * Solo se acredita sobre una factura viva.
     */
    private function guardCreditable(Sale $sale): void
    {
        if (! $sale->isIssued()) {
            throw SalesException::creditNoteNeedsIssuedSale($sale);
        }
    }

    /**
     * No se puede acreditar más de lo que dice la factura.
     *
     * Se suman las notas ya emitidas más esta: acreditar de más convertiría la
     * cuenta por cobrar en un saldo a favor del cliente que nadie autorizó, y
     * en el impuesto, en una devolución mayor que la que se cobró.
     */
    private function guardNotOverCrediting(CreditNote $note): void
    {
        $yaAcreditado = CreditNote::query()
            ->where('sale_id', $note->sale_id)
            ->whereKeyNot($note->id)
            ->issued()
            ->get()
            ->reduce(
                fn (Money $carry, CreditNote $other) => $carry->plus($other->totalAmount()),
                Money::zero(),
            );

        $total = $yaAcreditado->plus($note->totalAmount());

        if ($total->greaterThan($note->sale->totalAmount())) {
            throw SalesException::creditExceedsSale($note->sale, $total);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Interno
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data
     */
    private function reasonFrom(array $data): CreditNoteReason
    {
        return $data['reason'] instanceof CreditNoteReason
            ? $data['reason']
            : CreditNoteReason::from((string) ($data['reason'] ?? 'return'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function replaceLines(CreditNote $note, Sale $sale, array $lines): void
    {
        $note->items()->delete();

        $number = 1;

        foreach ($lines as $line) {
            $saleItem = isset($line['sale_item_id'])
                ? SaleItem::query()->where('sale_id', $sale->id)->find($line['sale_item_id'])
                : null;

            $quantity = (string) $line['quantity'];

            if ($saleItem !== null) {
                $this->guardQuantityWithinSale($saleItem, $quantity);
            }

            $tax = isset($line['tax_id'])
                ? Tax::query()->find($line['tax_id'])
                : $saleItem?->tax;

            $item = new CreditNoteItem;
            $item->forceFill([
                'credit_note_id' => $note->id,
                'company_id' => $note->company_id,
                'sale_item_id' => $saleItem?->id,
                'product_id' => $line['product_id'] ?? $saleItem?->product_id,
                'line_number' => $number++,
                'description' => $line['description'] ?? $saleItem?->description ?? '',
                'quantity' => $quantity,
                'unit_price' => (string) ($line['unit_price'] ?? $saleItem?->unit_price ?? '0'),
                'discount_rate' => (string) ($line['discount_rate'] ?? $saleItem?->discount_rate ?? '0'),
                'tax_id' => $tax?->id,
                'tax_rate' => $tax?->rate ?? '0',
                // El costo se copia de la línea de la factura, proporcional a la
                // cantidad que vuelve. No se consulta el promedio de hoy: lo que
                // salió a un costo tiene que volver al mismo, o la cuenta de
                // inventario y el kardex acabarían discrepando.
                'unit_cost' => $saleItem?->unit_cost ?? '0',
                'cost_total' => $this->proportionalCost($saleItem, $quantity)->toString(),
                'discount_amount' => '0',
                'tax_amount' => '0',
                'subtotal' => '0',
                'total' => '0',
            ])->save();
        }

        $this->recalculate($note->refresh());
    }

    /**
     * Costo de la parte que se devuelve.
     *
     * Si vuelve la línea entera, vuelve el costo entero —sin recalcular, para no
     * perder el centavo del redondeo—. Si vuelve una parte, se prorratea.
     */
    private function proportionalCost(?SaleItem $saleItem, string $quantity): Money
    {
        if ($saleItem === null || $saleItem->costAmount()->isZero()) {
            return Money::zero();
        }

        $sold = Money::of((string) $saleItem->quantity);
        $returning = Money::of($quantity);

        if ($returning->equals($sold)) {
            return $saleItem->costAmount();
        }

        return Money::ofRounded(
            bcdiv(
                bcmul($saleItem->costAmount()->toString(), $returning->toString(), 8),
                $sold->toString(),
                8,
            )
        );
    }

    private function guardQuantityWithinSale(SaleItem $saleItem, string $quantity): void
    {
        $yaDevuelto = CreditNoteItem::query()
            ->where('sale_item_id', $saleItem->id)
            ->whereHas('creditNote', fn ($q) => $q->issued())
            ->sum('quantity');

        $total = bcadd((string) $yaDevuelto, $quantity, 6);

        if (bccomp($total, (string) $saleItem->quantity, 6) === 1) {
            throw SalesException::creditQuantityExceedsSale($saleItem, $total);
        }
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
