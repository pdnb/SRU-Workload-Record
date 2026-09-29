<?php

namespace Tests;

use App\Models\FiscalYear;
use App\Models\Position;
use App\Models\WorkloadStructure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The position most tests use, mirroring the production default (0901).
     */
    protected function defaultPosition(): Position
    {
        return Position::firstOrCreate(
            ['code' => '0901'],
            ['name' => 'นักวิชาการคอมพิวเตอร์-ปฏิบัติการ', 'is_active' => true],
        );
    }

    protected function structureFor(FiscalYear|int $fiscalYear, ?Position $position = null): WorkloadStructure
    {
        return WorkloadStructure::firstOrCreate([
            'fiscal_year_id' => $fiscalYear instanceof FiscalYear ? $fiscalYear->id : $fiscalYear,
            'position_id' => ($position ?? $this->defaultPosition())->id,
        ]);
    }
}
