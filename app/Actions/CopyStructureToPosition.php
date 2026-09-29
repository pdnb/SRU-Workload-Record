<?php

namespace App\Actions;

use App\Models\Position;
use App\Models\WorkloadStructure;
use Illuminate\Support\Facades\DB;

class CopyStructureToPosition
{
    public function __construct(
        private CopyWorkloadGroupsToStructure $copyGroups,
    ) {}

    /**
     * Copies a structure into another position's structure in the same fiscal year.
     */
    public function __invoke(WorkloadStructure $source, Position $targetPosition, bool $replaceExisting = false): WorkloadStructure
    {
        return DB::transaction(function () use ($source, $targetPosition, $replaceExisting) {
            if ($source->position_id === $targetPosition->id) {
                throw new \RuntimeException('same_position');
            }

            $target = WorkloadStructure::firstOrCreate([
                'fiscal_year_id' => $source->fiscal_year_id,
                'position_id' => $targetPosition->id,
            ]);

            if ($target->isFromOracle()) {
                throw new \RuntimeException('target_is_oracle');
            }

            $hasGroups = $target->workloadGroups()->exists();

            if ($hasGroups && ! $replaceExisting) {
                throw new \RuntimeException('target_has_structure');
            }

            if ($hasGroups) {
                $target->workloadGroups()->delete();
            }

            $source->load('workloadGroups.workloadMains.workloads');
            ($this->copyGroups)($source->workloadGroups, $target);

            return $target;
        });
    }
}
