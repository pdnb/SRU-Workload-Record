<?php

namespace App\Actions;

use App\Enums\PeriodStatus;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use Illuminate\Support\Facades\DB;

class CopyStructureToNextFiscalYear
{
    public function __invoke(FiscalYear $source, bool $replaceExisting = false): FiscalYear
    {
        return DB::transaction(function () use ($source, $replaceExisting) {
            $targetYear = $source->year + 1;

            $target = FiscalYear::firstOrCreate(
                ['year' => $targetYear],
                ['is_active' => false],
            );

            $existingGroups = WorkloadGroup::where('fiscal_year_id', $target->id)->exists();

            if ($existingGroups && ! $replaceExisting) {
                throw new \RuntimeException('target_has_structure');
            }

            if ($existingGroups && $replaceExisting) {
                WorkloadGroup::where('fiscal_year_id', $target->id)->delete();
                EvaluationPeriod::where('fiscal_year_id', $target->id)->delete();
            }

            $source->load(['evaluationPeriods', 'workloadGroups.workloadMains.workloads']);

            foreach ($source->evaluationPeriods as $period) {
                EvaluationPeriod::create([
                    'fiscal_year_id' => $target->id,
                    'period' => $period->period,
                    'label' => str_replace((string) $source->year, (string) $targetYear, $period->label),
                    'start_date' => $period->start_date->addYear(),
                    'end_date' => $period->end_date->addYear(),
                    'status' => PeriodStatus::Draft,
                ]);
            }

            foreach ($source->workloadGroups as $group) {
                $newGroup = WorkloadGroup::create([
                    'fiscal_year_id' => $target->id,
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

            return $target;
        });
    }
}
