<?php

declare(strict_types=1);

namespace App\Domains\Treasury\Models;

use App\Domains\Accounting\Models\JournalEntryLine;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marca de que una línea del libro apareció en el extracto.
 *
 * No lleva importe: el importe es el de la línea del diario. Copiarlo aquí
 * abriría la puerta a que los dos números discreparan.
 */
class BankReconciliationLine extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'cleared_on' => 'date',
        ];
    }

    /** @return BelongsTo<BankReconciliation, $this> */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    /** @return BelongsTo<JournalEntryLine, $this> */
    public function journalEntryLine(): BelongsTo
    {
        return $this->belongsTo(JournalEntryLine::class);
    }
}
