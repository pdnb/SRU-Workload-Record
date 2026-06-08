<?php

namespace App\Support;

use App\Models\WorkloadGroup;
use Illuminate\Support\Collection;

class WorkloadStructureNumbering
{
    /**
     * @param  Collection<int, WorkloadGroup>  $groups
     * @return array{groups: array<int, string>, mains: array<int, string>, workloads: array<int, string>}
     */
    public static function buildMap(Collection $groups): array
    {
        $map = [
            'groups' => [],
            'mains' => [],
            'workloads' => [],
        ];

        foreach ($groups->values() as $groupIndex => $group) {
            $groupNumber = (string) ($groupIndex + 1);
            $map['groups'][$group->id] = $groupNumber;

            foreach ($group->workloadMains->values() as $mainIndex => $main) {
                $mainNumber = $groupNumber.'.'.($mainIndex + 1);
                $map['mains'][$main->id] = $mainNumber;

                foreach ($main->workloads->values() as $workloadIndex => $workload) {
                    $map['workloads'][$workload->id] = $mainNumber.'.'.($workloadIndex + 1);
                }
            }
        }

        return $map;
    }
}
