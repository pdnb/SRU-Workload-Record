<?php

namespace App\Actions;

use App\Models\FiscalYear;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use Illuminate\Support\Facades\DB;

class ImportWorkloadStructureFromPmsHtml
{
    /**
     * @param  list<array{name: string, max_score: float, mains: array<string, list<array{name: string, score: float}>>}>  $structure
     */
    public function __invoke(FiscalYear $fiscalYear, array $structure, bool $replaceExisting = false): void
    {
        DB::transaction(function () use ($fiscalYear, $structure, $replaceExisting) {
            $existingGroups = WorkloadGroup::where('fiscal_year_id', $fiscalYear->id)->exists();

            if ($existingGroups && ! $replaceExisting) {
                throw new \RuntimeException('fiscal_year_has_structure');
            }

            if ($existingGroups && $replaceExisting) {
                WorkloadGroup::where('fiscal_year_id', $fiscalYear->id)->delete();
            }

            foreach ($structure as $gIndex => $groupData) {
                $group = WorkloadGroup::create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'name' => $groupData['name'],
                    'max_score' => $groupData['max_score'],
                    'sort_order' => $gIndex + 1,
                ]);

                $mIndex = 0;

                foreach ($groupData['mains'] as $mainName => $workloads) {
                    $mIndex++;

                    $main = WorkloadMain::create([
                        'workload_group_id' => $group->id,
                        'name' => $mainName,
                        'sort_order' => $mIndex,
                    ]);

                    foreach ($workloads as $wIndex => $workload) {
                        Workload::create([
                            'workload_main_id' => $main->id,
                            'name' => $workload['name'],
                            'score' => $workload['score'],
                            'sort_order' => $wIndex + 1,
                        ]);
                    }
                }
            }
        });
    }
}
