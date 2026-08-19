<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Models;

use App\Domains\Tenancy\Models\Branch;
use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de partida. No tiene timestamps: pertenece a la partida y comparte su
 * ciclo de vida.
 *
 * @property int $id
 * @property int $journal_entry_id
 * @property int $account_id
 * @property int $line_number
 * @property string $debit
 * @property string $credit
 */
#[Fillable([
    'account_id', 'branch_id', 'line_number', 'description',
    'debit', 'credit', 'partner_type', 'partner_id', 'document_ref', 'foreign_amount',
])]
class JournalEntryLine extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'foreign_amount' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function debitAmount(): Money
    {
        return Money::of($this->debit);
    }

    public function creditAmount(): Money
    {
        return Money::of($this->credit);
    }

    public function isDebit(): bool
    {
        return $this->debitAmount()->isPositive();
    }

    /**
     * Importe de la línea, sin importar de qué lado esté.
     */
    public function amount(): Money
    {
        return $this->isDebit() ? $this->debitAmount() : $this->creditAmount();
    }
}
