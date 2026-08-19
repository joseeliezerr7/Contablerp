<?php

declare(strict_types=1);

namespace App\Domains\Sales\Policies;

use App\Domains\Sales\Models\CreditNote;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class CreditNotePolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'sales.credit_notes.view');
    }

    public function view(User $user, CreditNote $note): bool
    {
        return $this->allows($user, 'sales.credit_notes.view', $note);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'sales.credit_notes.create');
    }

    public function update(User $user, CreditNote $note): bool
    {
        return $note->isDraft() && $this->allows($user, 'sales.credit_notes.create', $note);
    }

    public function delete(User $user, CreditNote $note): bool
    {
        return $note->isDraft() && $this->allows($user, 'sales.credit_notes.create', $note);
    }

    /**
     * Emitir es lo que consume un correlativo fiscal y rebaja el ingreso: es un
     * permiso distinto de capturar.
     */
    public function issue(User $user, CreditNote $note): bool
    {
        return $note->isDraft() && $this->allows($user, 'sales.credit_notes.issue', $note);
    }

    public function void(User $user, CreditNote $note): bool
    {
        return $note->isIssued() && $this->allows($user, 'sales.credit_notes.void', $note);
    }

    public function print(User $user, CreditNote $note): bool
    {
        return ! $note->isDraft() && $this->allows($user, 'sales.credit_notes.view', $note);
    }
}
