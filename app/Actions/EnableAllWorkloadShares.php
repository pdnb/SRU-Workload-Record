<?php

namespace App\Actions;

use App\Models\WorkloadShare;
use App\Models\WorkRecord;

class EnableAllWorkloadShares
{
    /**
     * Enable a public share link for every workload that has entries in the record.
     * Existing tokens are kept so links already given to external systems stay valid.
     */
    public function __invoke(WorkRecord $record): void
    {
        $workloadIds = $record->workEntries()
            ->reorder()
            ->distinct()
            ->pluck('workload_id');

        foreach ($workloadIds as $workloadId) {
            $share = WorkloadShare::query()->firstOrNew([
                'work_record_id' => $record->id,
                'workload_id' => $workloadId,
            ]);

            if (! $share->exists) {
                $share->regenerateToken();
            }

            $share->enabled_at ??= now();
            $share->save();
        }
    }
}
