<?php

namespace Database\Seeders;

use App\Enums\EvaluationPeriodNumber;
use App\Enums\PeriodStatus;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use Illuminate\Database\Seeder;

class FiscalYearSeeder extends Seeder
{
    public function run(): void
    {
        $fy2568 = FiscalYear::create(['year' => 2568, 'is_active' => false]);

        EvaluationPeriod::create([
            'fiscal_year_id' => $fy2568->id,
            'period' => EvaluationPeriodNumber::One,
            'label' => 'รอบที่ 1 (ต.ค. – มี.ค. 2568)',
            'start_date' => '2024-10-01',
            'end_date' => '2025-03-31',
            'status' => PeriodStatus::Open,
        ]);

        EvaluationPeriod::create([
            'fiscal_year_id' => $fy2568->id,
            'period' => EvaluationPeriodNumber::Two,
            'label' => 'รอบที่ 2 (เม.ย. – ก.ย. 2568)',
            'start_date' => '2025-04-01',
            'end_date' => '2025-09-30',
            'status' => PeriodStatus::Open,
        ]);

        $fy2569 = FiscalYear::create(['year' => 2569, 'is_active' => true]);

        EvaluationPeriod::create([
            'fiscal_year_id' => $fy2569->id,
            'period' => EvaluationPeriodNumber::One,
            'label' => 'รอบที่ 1 (ต.ค. – มี.ค. 2569)',
            'start_date' => '2025-10-01',
            'end_date' => '2026-03-31',
            'status' => PeriodStatus::Open,
        ]);

        EvaluationPeriod::create([
            'fiscal_year_id' => $fy2569->id,
            'period' => EvaluationPeriodNumber::Two,
            'label' => 'รอบที่ 2 (เม.ย. – ก.ย. 2569)',
            'start_date' => '2026-04-01',
            'end_date' => '2026-09-30',
            'status' => PeriodStatus::Open,
        ]);
    }
}
