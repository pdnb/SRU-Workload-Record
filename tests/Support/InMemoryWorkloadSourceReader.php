<?php

namespace Tests\Support;

use App\Contracts\WorkloadSourceReader;
use App\Data\Oracle\PositionRow;
use App\Data\Oracle\StaffPositionRow;
use App\Data\Oracle\StructureItemRow;

/**
 * Stand-in for Oracle in tests: holds rows in arrays and filters them the same
 * way the real reader does.
 */
class InMemoryWorkloadSourceReader implements WorkloadSourceReader
{
    /**
     * @param  list<PositionRow>  $positions
     * @param  list<StructureItemRow>  $structureItems
     * @param  list<StaffPositionRow>  $staffPositions
     */
    public function __construct(
        public array $positions = [],
        public array $structureItems = [],
        public array $staffPositions = [],
    ) {}

    public function positions(): iterable
    {
        return $this->positions;
    }

    public function structureItems(?int $fiscalYear = null): iterable
    {
        return array_values(array_filter(
            $this->structureItems,
            fn (StructureItemRow $row) => $fiscalYear === null || $row->fiscalYear === $fiscalYear,
        ));
    }

    public function staffPositions(): iterable
    {
        return $this->staffPositions;
    }

    public function staffPositionFor(?string $employeeId, ?string $email): ?StaffPositionRow
    {
        foreach ($this->staffPositions as $row) {
            if (($employeeId !== null && $row->employeeId === $employeeId)
                || ($email !== null && in_array(mb_strtolower($email), [$row->email, $row->altEmail], true))) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Shorthand for one workload row: [group, main, workload] ids double as names.
     */
    public static function item(
        int $year,
        string $position,
        string $group,
        string $main,
        string $workload,
        ?float $score = 1.0,
        ?float $groupMax = null,
        ?float $mainMax = null,
        ?string $workloadName = null,
    ): StructureItemRow {
        return new StructureItemRow(
            fiscalYear: $year,
            positionCode: $position,
            groupId: $group,
            groupName: "กลุ่ม {$group}",
            groupMaxScore: $groupMax,
            groupOrder: null,
            mainId: $main,
            mainName: "หมวด {$main}",
            mainMaxScore: $mainMax,
            mainOrder: null,
            workloadId: $workload,
            workloadName: $workloadName ?? "ภาระงาน {$workload}",
            workloadScore: $score,
            workloadOrder: null,
        );
    }
}
