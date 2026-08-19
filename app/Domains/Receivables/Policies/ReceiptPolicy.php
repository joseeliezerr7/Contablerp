<?php

declare(strict_types=1);

namespace App\Domains\Receivables\Policies;

use App\Domains\Receivables\Models\Receipt;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class ReceiptPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'receipts.view');
    }

    public function view(User $user, Receipt $receipt): bool
    {
        return $this->allows($user, 'receipts.view', $receipt);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'receipts.create');
    }

    /**
     * Un recibo no se edita nunca: se anula y se emite otro. Cambiarlo dejaría
     * al cliente con un comprobante que ya no coincide con lo registrado.
     */
    public function update(User $user, Receipt $receipt): bool
    {
        return false;
    }

    public function void(User $user, Receipt $receipt): bool
    {
        return ! $receipt->isVoided() && $this->allows($user, 'receipts.void', $receipt);
    }
}
