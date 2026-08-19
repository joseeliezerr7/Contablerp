<?php

declare(strict_types=1);

namespace App\Domains\Assets\Policies;

use App\Domains\Assets\Models\WithholdingType;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class WithholdingTypePolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'taxes.withholdings.view');
    }

    public function view(User $user, WithholdingType $type): bool
    {
        return $this->allows($user, 'taxes.withholdings.view', $type);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'taxes.withholdings.manage');
    }

    public function update(User $user, WithholdingType $type): bool
    {
        return $this->allows($user, 'taxes.withholdings.manage', $type);
    }

    public function delete(User $user, WithholdingType $type): bool
    {
        return $this->allows($user, 'taxes.withholdings.manage', $type);
    }
}
