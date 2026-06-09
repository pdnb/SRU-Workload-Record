<?php

namespace App\Policies;

use App\Enums\WorkRecordStatus;
use App\Models\User;
use App\Models\WorkRecord;
use App\Models\WorkloadShare;

class WorkloadSharePolicy
{
    public function manage(User $user, WorkloadShare $share): bool
    {
        return $share->workRecord->user_id === $user->id;
    }

    public function manageRecordWorkload(User $user, WorkRecord $record, int $workloadId): bool
    {
        if ($record->user_id !== $user->id) {
            return false;
        }

        if (! in_array($record->status, [WorkRecordStatus::Submitted, WorkRecordStatus::Approved], true)) {
            return false;
        }

        return $record->workEntries()->where('workload_id', $workloadId)->exists();
    }
}
