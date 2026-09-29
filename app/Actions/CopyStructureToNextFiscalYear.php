<?php

namespace App\Actions;

use App\Enums\PeriodStatus;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use App\Models\WorkloadGroup;
use App\Models\WorkloadStructure;
use Illuminate\Support\Facades\DB;

class CopyStructureToNextFiscalYear
{
    public function __construct(
        private CopyWorkloadGroupsToStructure $copyGroups,
    ) {}

    /**
     * Copies every position's structure and all evaluation periods to year + 1.
     */
    public function __invoke(FiscalYear $source, bool $replaceExisting = false): FiscalYear
    {
        return DB::transaction(function () use ($source, $replaceExisting) {
            $targetYear = $source->year + 1;

            $target = FiscalYear::firstOrCreate(
                ['year' => $targetYear],
                ['is_active' => false],
            );

            $targetStructureIds = WorkloadStructure::where('fiscal_year_id', $target->id)->pluck('id');
            $existingGroups = WorkloadGroup::whereIn('workload_structure_id', $targetStructureIds)->exists();

            if ($existingGroups && ! $replaceExisting) {
                throw new \RuntimeException('target_has_structure');
            }

            if ($existingGroups && $replaceExisting) {
                WorkloadGroup::whereIn('workload_structure_id', $targetStructureIds)->delete();
                EvaluationPeriod::where('fiscal_year_id', $target->id)->delete();
            }

            $source->load(['evaluationPeriods', 'workloadStructures.workloadGroups.workloadMains.workloads']);

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

            foreach ($source->workloadStructures as $structure) {
                $targetStructure = WorkloadStructure::firstOrCreate(
                    ['fiscal_year_id' => $target->id, 'position_id' => $structure->position_id],
                    ['name' => $structure->name],
                );

                ($this->copyGroups)($structure->workloadGroups, $targetStructure);
            }

            return $target;
        });
    }
}
