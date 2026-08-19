<?php

declare(strict_types=1);

namespace App\Domains\Assets\Policies;

use App\Domains\Assets\Models\DepreciationRun;
use App\Models\User;
use App\Support\Tenancy\CompanyScopedPolicy;

class DepreciationRunPolicy extends CompanyScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'assets.depreciation.view');
    }

    public function view(User $user, DepreciationRun $run): bool
    {
        return $this->allows($user, 'assets.depreciation.view', $run);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'assets.depreciation.run');
    }

    public function void(User $user, DepreciationRun $run): bool
    {
        return $run->isPosted() && $this->allows($user, 'assets.depreciation.void', $run);
    }
}
