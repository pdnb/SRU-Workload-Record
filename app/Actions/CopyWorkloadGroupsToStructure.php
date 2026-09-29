<?php

namespace App\Actions;

use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use App\Models\WorkloadStructure;

/**
 * Deep-copies groups (with their mains and workloads) into another structure.
 * Copies are always local: external ids are not carried over.
 */
class CopyWorkloadGroupsToStructure
{
    /**
     * @param  iterable<WorkloadGroup>  $groups  groups with workloadMains.workloads loaded
     */
    public function __invoke(iterable $groups, WorkloadStructure $target): void
    {
        foreach ($groups as $group) {
            $newGroup = WorkloadGroup::create([
                'workload_structure_id' => $target->id,
                'name' => $group->name,
                'max_score' => $group->max_score,
                'sort_order' => $group->sort_order,
            ]);

            foreach ($group->workloadMains as $main) {
                $newMain = WorkloadMain::create([
                    'workload_group_id' => $newGroup->id,
                    'name' => $main->name,
                    'max_score' => $main->max_score,
                    'sort_order' => $main->sort_order,
                ]);

                foreach ($main->workloads as $workload) {
                    Workload::create([
                        'workload_main_id' => $newMain->id,
                        'name' => $workload->name,
                        'score' => $workload->score,
                        'sort_order' => $workload->sort_order,
                    ]);
                }
            }
        }
    }
}
