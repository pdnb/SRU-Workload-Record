<?php

namespace App\Actions;

use App\Models\Workload;
use App\Models\WorkloadMain;
use Illuminate\Support\Facades\DB;

class CloneWorkloadMain
{
    public function __invoke(WorkloadMain $main): WorkloadMain
    {
        return DB::transaction(function () use ($main) {
            $clone = WorkloadMain::create([
                'workload_group_id' => $main->workload_group_id,
                'name' => $main->name.' (สำเนา)',
                'max_score' => $main->max_score,
                'sort_order' => $main->sort_order + 1,
            ]);

            foreach ($main->workloads as $workload) {
                Workload::create([
                    'workload_main_id' => $clone->id,
                    'name' => $workload->name,
                    'score' => $workload->score,
                    'sort_order' => $workload->sort_order,
                ]);
            }

            return $clone->load('workloads');
        });
    }
}
