<?php

declare(strict_types=1);

namespace App\Domains\Sales\Models;

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Fiscal\Models\FiscalAuthorization;
use App\Domains\Partners\Models\Customer;
use App\Domains\Receivables\Models\Receivable;
use App\Domains\Sales\Enums\PaymentCondition;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Policies\SalePolicy;
use App\Domains\Tenancy\Models\Branch;
use App\Domains\Tenancy\Models\Warehouse;
use App\Models\User;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Database\Factories\SaleFactory;
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
 * Factura de venta.
 *
 * @property int $id
 * @property string|null $number
 * @property CarbonInterface $date
 * @property SaleStatus $status
 * @property PaymentCondition $payment_condition
 * @property string $total
 */
#[UseFactory(SaleFactory::class)]
#[UsePolicy(SalePolicy::class)]
#[Fillable([
    'branch_id', 'warehouse_id', 'customer_id', 'date', 'due_date',
    'payment_condition', 'credit_days', 'deposit_account_id',
    'reference', 'notes',
])]
class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use BelongsToCompany, HasFactory;

    public const SOURCE_TYPE = 'sale';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'due_date' => 'date',
            'payment_condition' => PaymentCondition::class,
            'status' => SaleStatus::class,
            'credit_days' => 'integer',
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

    /** @return BelongsTo<FiscalAuthorization, $this> */
    public function authorization(): BelongsTo
    {
        return $this->belongsTo(FiscalAuthorization::class, 'fiscal_authorization_id');
    }

    /** @return HasMany<CreditNote, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    /** @return HasMany<SalePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /**
     * El rango autorizado tal como debe imprimirse.
     *
     * Se arma con los datos **congelados en la factura**, no con los de la
     * autorización: si se leyera de la autorización, reimprimir una factura
     * vieja mostraría el rango del CAI de hoy, que esa factura nunca llevó.
     */
    public function fiscalRangeLabel(): ?string
    {
        if ($this->fiscal_range_from === null || $this->number === null) {
            return null;
        }

        // Las tres primeras partes del número son comunes a todo el rango.
        $prefix = mb_substr($this->number, 0, mb_strrpos($this->number, '-') + 1);

        return $prefix.str_pad((string) $this->fiscal_range_from, 8, '0', STR_PAD_LEFT)
            .' al '.$prefix.str_pad((string) $this->fiscal_range_to, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Importe ya acreditado por notas de crédito emitidas.
     */
    public function creditedAmount(): Money
    {
        return Money::sum(
            $this->creditNotes()->issued()->get()
                ->map(fn (CreditNote $note) => $note->totalAmount())
                ->all()
        );
    }

    /**
     * Lo que queda de la factura después de las notas de crédito.
     */
    public function netAmount(): Money
    {
        return $this->totalAmount()->minus($this->creditedAmount());
    }

    /** @return HasMany<SaleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class)->orderBy('line_number');
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

    /** @return BelongsTo<Account, $this> */
    public function depositAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'deposit_account_id');
    }

    /** @return HasOne<Receivable, $this> */
    public function receivable(): HasOne
    {
        return $this->hasOne(Receivable::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Partida contable de la factura. No hay columna que las una: el vínculo
     * vive en `journal_entries.source_type`/`source_id`, con un índice único
     * que garantiza que solo haya una partida vigente por documento.
     */
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

    public function isOnCredit(): bool
    {
        return $this->payment_condition === PaymentCondition::Credit;
    }

    /** @param  Builder<self>  $query */
    public function scopeIssued(Builder $query): void
    {
        $query->where('status', SaleStatus::Issued);
    }

    /** @param  Builder<self>  $query */
    public function scopeBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): void
    {
        $query->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')]);
    }
}
