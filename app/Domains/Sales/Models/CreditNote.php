<?php

declare(strict_types=1);

namespace App\Domains\Sales\Models;

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Fiscal\Models\FiscalAuthorization;
use App\Domains\Partners\Models\Customer;
use App\Domains\Sales\Enums\CreditNoteReason;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Policies\CreditNotePolicy;
use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Warehouse;
use App\Models\User;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nota de crédito sobre una factura emitida.
 *
 * Reutiliza `SaleStatus` a propósito: borrador, emitida y anulada significan
 * aquí exactamente lo mismo que en la factura, y duplicar el enum solo habría
 * creado dos verdades sobre qué es un documento vigente.
 *
 * @property string|null $number
 * @property CarbonInterface $date
 * @property SaleStatus $status
 * @property CreditNoteReason $reason
 * @property string $total
 */
#[UsePolicy(CreditNotePolicy::class)]
#[Fillable([
    'branch_id', 'customer_id', 'sale_id', 'date', 'reason',
    'description', 'restocks', 'warehouse_id',
])]
class CreditNote extends Model
{
    use BelongsToCompany;

    public const SOURCE_TYPE = 'credit_note';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'reason' => CreditNoteReason::class,
            'status' => SaleStatus::class,
            'restocks' => 'boolean',
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'exchange_rate' => 'decimal:8',
            'fiscal_range_from' => 'integer',
            'fiscal_range_to' => 'integer',
            'fiscal_sequence' => 'integer',
            'fiscal_limit_date' => 'date',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** @return HasMany<CreditNoteItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class)->orderBy('line_number');
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<FiscalAuthorization, $this> */
    public function authorization(): BelongsTo
    {
        return $this->belongsTo(FiscalAuthorization::class, 'fiscal_authorization_id');
    }

    /** @return BelongsTo<User, $this> */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Partida contable vigente del documento, si la tiene.
     */
    public function journalEntry(): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $this->id)
            ->where('status', '!=', JournalEntryStatus::Voided)
            ->first();
    }

    public function totalAmount(): Money
    {
        return Money::of($this->total);
    }

    public function subtotalAmount(): Money
    {
        return Money::of($this->subtotal);
    }

    public function taxAmount(): Money
    {
        return Money::of($this->tax_total);
    }

    public function discountAmount(): Money
    {
        return Money::of($this->discount_total);
    }

    public function isDraft(): bool
    {
        return $this->status === SaleStatus::Draft;
    }

    public function isIssued(): bool
    {
        return $this->status === SaleStatus::Issued;
    }

    public function isVoided(): bool
    {
        return $this->status === SaleStatus::Voided;
    }

    public function label(): string
    {
        return 'Nota de crédito '.($this->number ?? 'en borrador');
    }

    /** @param  Builder<self>  $query */
    public function scopeIssued(Builder $query): void
    {
        $query->where('status', SaleStatus::Issued);
    }
}
