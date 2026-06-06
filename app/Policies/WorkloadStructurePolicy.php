<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkloadGroup;

class WorkloadStructurePolicy
{
    public function manage(User $user, WorkloadGroup $group): bool
    {
        return $user->isAdmin();
    }
}
