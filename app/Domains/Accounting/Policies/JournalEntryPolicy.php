<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Policies;

use App\Domains\Accounting\Models\JournalEntry;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;

class JournalEntryPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->has() && $user->can('accounting.journal.view');
    }

    public function view(User $user, JournalEntry $entry): bool
    {
        return $this->inActiveCompany($entry) && $user->can('accounting.journal.view');
    }

    public function create(User $user): bool
    {
        return $this->context->has() && $user->can('accounting.journal.create');
    }

    /**
     * Solo los borradores se editan. Una partida contabilizada se corrige con
     * una reversión, no modificándola.
     */
    public function update(User $user, JournalEntry $entry): bool
    {
        return $this->inActiveCompany($entry)
            && $entry->isDraft()
            && $user->can('accounting.journal.update');
    }

    public function delete(User $user, JournalEntry $entry): bool
    {
        return $this->inActiveCompany($entry)
            && $entry->isDraft()
            && $user->can('accounting.journal.delete');
    }

    public function post(User $user, JournalEntry $entry): bool
    {
        return $this->inActiveCompany($entry)
            && $entry->isDraft()
            && $user->can('accounting.journal.post');
    }

    public function void(User $user, JournalEntry $entry): bool
    {
        return $this->inActiveCompany($entry)
            && $entry->isPosted()
            && $user->can('accounting.journal.void');
    }

    public function reverse(User $user, JournalEntry $entry): bool
    {
        return $this->inActiveCompany($entry)
            && $entry->isPosted()
            && $user->can('accounting.journal.reverse');
    }

    private function inActiveCompany(JournalEntry $entry): bool
    {
        return $this->context->has() && $entry->company_id === $this->context->id();
    }
}
