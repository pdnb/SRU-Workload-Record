<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkloadGroup;

class WorkloadStructurePolicy
{
    /**
     * Structures synced from Oracle are read-only; they are overwritten on every sync.
     */
    public function manage(User $user, WorkloadGroup $group): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        if ($group->workload_structure_id === null) {
            return true;
        }

        return ! (bool) $group->structure?->isFromOracle();
    }
}
