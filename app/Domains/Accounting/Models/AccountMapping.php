<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Models;

use App\Domains\Accounting\Policies\AccountMappingPolicy;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $key
 * @property int $account_id
 */
#[Fillable(['key', 'account_id'])]
#[UsePolicy(AccountMappingPolicy::class)]
class AccountMapping extends Model
{
    use BelongsToCompany;

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
