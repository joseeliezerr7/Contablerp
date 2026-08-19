<?php

declare(strict_types=1);

namespace App\Domains\Purchases\Models;

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Partners\Models\Supplier;
use App\Domains\Payables\Models\Payable;
use App\Domains\Purchases\Enums\PurchaseStatus;
use App\Domains\Purchases\Policies\PurchasePolicy;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Warehouse;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string|null $number
 * @property string $supplier_invoice_number
 * @property PurchaseStatus $status
 * @property PaymentCondition $payment_condition
 */
#[UseFactory(PurchaseFactory::class)]
#[UsePolicy(PurchasePolicy::class)]
#[Fillable([
    'branch_id', 'warehouse_id', 'supplier_id', 'supplier_invoice_number', 'date', 'due_date',
    'payment_condition', 'credit_days', 'payment_account_id', 'notes',
])]
class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use BelongsToCompany, HasFactory;

    public const SOURCE_TYPE = 'purchase';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'due_date' => 'date',
            'payment_condition' => PaymentCondition::class,
            'status' => PurchaseStatus::class,
            'credit_days' => 'integer',
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'exchange_rate' => 'decimal:8',
            'received_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** @return HasMany<PurchaseItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class)->orderBy('line_number');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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

    /** @return BelongsTo<Account, $this> */
    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
    }

    /** @return HasOne<Payable, $this> */
    public function payable(): HasOne
    {
        return $this->hasOne(Payable::class);
    }

    public function journalEntry(): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $this->id)
            ->where('status', '!=', JournalEntryStatus::Voided)
            ->first();
    }

    public function subtotalAmount(): Money
    {
        return Money::of($this->subtotal);
    }

    public function discountAmount(): Money
    {
        return Money::of($this->discount_total);
    }

    public function taxAmount(): Money
    {
        return Money::of($this->tax_total);
    }

    public function totalAmount(): Money
    {
        return Money::of($this->total);
    }

    public function isDraft(): bool
    {
        return $this->status === PurchaseStatus::Draft;
    }

    public function isReceived(): bool
    {
        return $this->status === PurchaseStatus::Received;
    }

    public function isVoided(): bool
    {
        return $this->status === PurchaseStatus::Voided;
    }

    public function isOnCredit(): bool
    {
        return $this->payment_condition === PaymentCondition::Credit;
    }

    public function label(): string
    {
        return $this->number.' · '.$this->supplier_invoice_number;
    }

    /** @param  Builder<self>  $query */
    public function scopeReceived(Builder $query): void
    {
        $query->where('status', PurchaseStatus::Received);
    }
}
