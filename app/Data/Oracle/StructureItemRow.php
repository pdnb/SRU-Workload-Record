<?php

namespace App\Data\Oracle;

/**
 * One workload item with its parent group and main, as the flat Oracle dataset returns it.
 * `fiscalYear` is always a Buddhist-era year (e.g. 2569), matching fiscal_years.year.
 */
readonly class StructureItemRow
{
    public function __construct(
        public int $fiscalYear,
        public string $positionCode,
        public string $groupId,
        public string $groupName,
        public ?float $groupMaxScore,
        public ?int $groupOrder,
        public string $mainId,
        public string $mainName,
        public ?float $mainMaxScore,
        public ?int $mainOrder,
        public string $workloadId,
        public string $workloadName,
        public ?float $workloadScore,
        public ?int $workloadOrder,
    ) {}
}
