<?php

namespace App\Actions;

use App\Models\Workload;
use Illuminate\Support\Facades\DB;

class CloneWorkload
{
    public function __invoke(Workload $workload): Workload
    {
        return DB::transaction(function () use ($workload) {
            return Workload::create([
                'workload_main_id' => $workload->workload_main_id,
                'name' => $workload->name.' (สำเนา)',
                'score' => $workload->score,
                'sort_order' => $workload->sort_order + 1,
            ]);
        });
    }
}
