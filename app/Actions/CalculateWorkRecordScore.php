<?php

namespace App\Actions;

use App\Data\WorkRecordScoreSummary;
use App\Models\WorkEntry;
use App\Models\WorkloadGroup;
use App\Models\WorkRecord;

class CalculateWorkRecordScore
{
    public function __invoke(WorkRecord $record): WorkRecordScoreSummary
    {
        $record->loadMissing([
            'workEntries.workload.workloadMain.workloadGroup',
            'evaluationPeriod',
        ]);

        $entriesByMain = $record->workEntries->groupBy(
            fn (WorkEntry $entry) => $entry->workload->workload_main_id,
        );

        $groupTotals = [];

        foreach ($entriesByMain as $mainEntries) {
            $main = $mainEntries->first()->workload->workloadMain;
            $groupId = $main->workload_group_id;

            $mainTotal = $mainEntries->sum(fn (WorkEntry $entry) => $this->entryScore($entry));

            if ($main->max_score !== null) {
                $mainTotal = min($mainTotal, (float) $main->max_score);
            }

            $groupTotals[$groupId] = ($groupTotals[$groupId] ?? 0) + $mainTotal;
        }

        $earned = 0.0;

        foreach ($groupTotals as $groupId => $groupTotal) {
            $group = WorkloadGroup::find($groupId);

            if ($group?->max_score !== null) {
                $groupTotal = min($groupTotal, (float) $group->max_score);
            }

            $earned += $groupTotal;
        }

        $fiscalYearId = $record->evaluationPeriod->fiscal_year_id;
        $maximumSum = (float) WorkloadGroup::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->whereNotNull('max_score')
            ->sum('max_score');

        return new WorkRecordScoreSummary(
            earned: $earned,
            maximum: $maximumSum > 0 ? $maximumSum : null,
        );
    }

    private function entryScore(WorkEntry $entry): float
    {
        $score = $entry->workload?->score;

        if ($score === null) {
            return 0.0;
        }

        return (float) $score * (float) ($entry->hours ?? 0);
    }
}
