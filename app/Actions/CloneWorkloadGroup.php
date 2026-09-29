<?php

namespace App\Actions;

use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use Illuminate\Support\Facades\DB;

class CloneWorkloadGroup
{
    public function __invoke(WorkloadGroup $group): WorkloadGroup
    {
        return DB::transaction(function () use ($group) {
            $group->load(['workloadMains.workloads']);

            $clone = WorkloadGroup::create([
                'workload_structure_id' => $group->workload_structure_id,
                'name' => $group->name.' (สำเนา)',
                'max_score' => $group->max_score,
                'sort_order' => $group->sort_order + 1,
            ]);

            foreach ($group->workloadMains as $main) {
                $mainClone = WorkloadMain::create([
                    'workload_group_id' => $clone->id,
                    'name' => $main->name,
                    'max_score' => $main->max_score,
                    'sort_order' => $main->sort_order,
                ]);

                foreach ($main->workloads as $workload) {
                    Workload::create([
                        'workload_main_id' => $mainClone->id,
                        'name' => $workload->name,
                        'score' => $workload->score,
                        'sort_order' => $workload->sort_order,
                    ]);
                }
            }

            return $clone->load(['workloadMains.workloads']);
        });
    }
}
