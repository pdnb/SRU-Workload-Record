<?php

namespace Tests\Feature;

use App\Actions\CopyStructureToNextFiscalYear;
use App\Enums\PeriodStatus;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CopyStructureToNextFiscalYearTest extends TestCase
{
    use RefreshDatabase;

    public function test_copy_keeps_periods_that_already_exist_in_target_year(): void
    {
        $source = FiscalYear::create(['year' => 2569, 'is_active' => true]);
        $target = FiscalYear::create(['year' => 2570, 'is_active' => false]);

        foreach ([$source, $target] as $year) {
            EvaluationPeriod::create([
                'fiscal_year_id' => $year->id,
                'period' => 1,
                'label' => "รอบที่ 1 {$year->year}",
                'start_date' => '2025-10-01',
                'end_date' => '2026-03-31',
                'status' => PeriodStatus::Draft,
            ]);
        }

        EvaluationPeriod::create([
            'fiscal_year_id' => $source->id,
            'period' => 2,
            'label' => 'รอบที่ 2 2569',
            'start_date' => '2026-04-01',
            'end_date' => '2026-09-30',
            'status' => PeriodStatus::Draft,
        ]);

        app(CopyStructureToNextFiscalYear::class)($source);

        $this->assertSame([1, 2], EvaluationPeriod::where('fiscal_year_id', $target->id)->orderBy('period')->get()->map(fn ($p) => $p->period->value)->all());
        $this->assertSame('รอบที่ 1 2570', EvaluationPeriod::where('fiscal_year_id', $target->id)->where('period', 1)->value('label'));
    }
}
