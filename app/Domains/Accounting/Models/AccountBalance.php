<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Models;

use App\Support\Money;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento acumulado de una cuenta en un período. Materialización de
 * journal_entry_lines para no recorrer el diario en cada reporte.
 *
 * @property int $account_id
 * @property int $accounting_period_id
 * @property string $period_debit
 * @property string $period_credit
 */
#[Fillable(['account_id', 'accounting_period_id', 'period_debit', 'period_credit'])]
class AccountBalance extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'period_debit' => 'decimal:4',
            'period_credit' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<AccountingPeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function debit(): Money
    {
        return Money::of($this->period_debit);
    }

    public function credit(): Money
    {
        return Money::of($this->period_credit);
    }
}
