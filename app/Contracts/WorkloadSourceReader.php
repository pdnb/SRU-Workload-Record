<?php

namespace App\Contracts;

use App\Data\Oracle\PositionRow;
use App\Data\Oracle\StaffPositionRow;
use App\Data\Oracle\StructureItemRow;

/**
 * Read-only source of positions, per-position workload structures and staff positions.
 * Production reads Oracle; tests bind an in-memory implementation.
 */
interface WorkloadSourceReader
{
    /** @return iterable<PositionRow> */
    public function positions(): iterable;

    /**
     * @param  int|null  $fiscalYear  Buddhist-era year; null = every year
     * @return iterable<StructureItemRow>
     */
    public function structureItems(?int $fiscalYear = null): iterable;

    /** @return iterable<StaffPositionRow> */
    public function staffPositions(): iterable;

    public function staffPositionFor(?string $employeeId, ?string $email): ?StaffPositionRow;
}
