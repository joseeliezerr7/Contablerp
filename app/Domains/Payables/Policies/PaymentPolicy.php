<?php

declare(strict_types=1);

namespace App\Domains\Payables\Policies;

use App\Domains\Payables\Models\Payment;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class PaymentPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'payments.view');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->allows($user, 'payments.view', $payment);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'payments.create');
    }

    /**
     * Un pago no se edita: se anula y se emite otro. Cambiarlo dejaría al
     * proveedor con un comprobante que ya no coincide con lo registrado.
     */
    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    public function void(User $user, Payment $payment): bool
    {
        return ! $payment->isVoided() && $this->allows($user, 'payments.void', $payment);
    }
}
