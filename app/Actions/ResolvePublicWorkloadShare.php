<?php

namespace App\Actions;

use App\Enums\WorkRecordStatus;
use App\Models\WorkEntryEvidence;
use App\Models\WorkloadShare;

class ResolvePublicWorkloadShare
{
    public function __invoke(string $token): WorkloadShare
    {
        $share = WorkloadShare::query()
            ->where('token', $token)
            ->whereNotNull('enabled_at')
            ->with('workRecord')
            ->first();

        abort_if($share === null, 404);

        abort_unless(
            in_array($share->workRecord->status, [WorkRecordStatus::Submitted, WorkRecordStatus::Approved], true),
            404,
        );

        return $share;
    }

    public function evidenceBelongsToShare(WorkloadShare $share, WorkEntryEvidence $evidence): bool
    {
        $evidence->loadMissing('workEntry');

        $entry = $evidence->workEntry;

        if ($entry === null) {
            return false;
        }

        return $entry->work_record_id === $share->work_record_id
            && $entry->workload_id === $share->workload_id;
    }
}
