<?php

declare(strict_types=1);

namespace App\Domains\Sales\Policies;

use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class SalePolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'sales.invoices.view');
    }

    public function view(User $user, Sale $sale): bool
    {
        return $this->allows($user, 'sales.invoices.view', $sale);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'sales.invoices.create');
    }

    /**
     * Solo el borrador se edita. Una factura emitida se corrige anulándola y
     * emitiendo otra, que es lo que deja rastro para el fisco.
     */
    public function update(User $user, Sale $sale): bool
    {
        return $sale->isDraft() && $this->allows($user, 'sales.invoices.update', $sale);
    }

    public function delete(User $user, Sale $sale): bool
    {
        return $sale->isDraft() && $this->allows($user, 'sales.invoices.delete', $sale);
    }

    public function issue(User $user, Sale $sale): bool
    {
        return $sale->isDraft() && $this->allows($user, 'sales.invoices.issue', $sale);
    }

    public function void(User $user, Sale $sale): bool
    {
        return $sale->isIssued() && $this->allows($user, 'sales.invoices.void', $sale);
    }

    public function print(User $user, Sale $sale): bool
    {
        return ! $sale->isDraft() && $this->allows($user, 'sales.invoices.print', $sale);
    }
}
