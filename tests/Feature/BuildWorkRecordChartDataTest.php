<?php

namespace Tests\Feature;

use App\Actions\BuildWorkRecordChartData;
use App\Enums\EvaluationPeriodNumber;
use App\Enums\PeriodStatus;
use App\Enums\UserRole;
use App\Enums\WorkRecordStatus;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\WorkEntry;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use App\Models\WorkRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildWorkRecordChartDataTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, period: EvaluationPeriod, workload: Workload, group: WorkloadGroup, record: WorkRecord}
     */
    private function createChartContext(): array
    {
        $user = User::factory()->create(['position_id' => $this->defaultPosition()->id, 'role' => UserRole::Staff]);

        $fiscalYear = FiscalYear::create(['year' => 2568, 'is_active' => true]);

        $period = EvaluationPeriod::create([
            'fiscal_year_id' => $fiscalYear->id,
            'period' => EvaluationPeriodNumber::One,
            'label' => 'รอบที่ 1',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'status' => PeriodStatus::Open,
        ]);

        $group = WorkloadGroup::create([
            'workload_structure_id' => $this->structureFor($fiscalYear->id)->id,
            'name' => 'กลุ่มทดสอบ',
            'max_score' => 100,
            'sort_order' => 1,
        ]);

        $main = WorkloadMain::create([
            'workload_group_id' => $group->id,
            'name' => 'หมวดทดสอบ',
            'sort_order' => 1,
        ]);

        $workload = Workload::create([
            'workload_main_id' => $main->id,
            'name' => 'ภาระงานทดสอบ',
            'score' => 10,
            'sort_order' => 1,
        ]);

        $record = WorkRecord::create([
            'user_id' => $user->id,
            'evaluation_period_id' => $period->id,
            'status' => WorkRecordStatus::Draft,
        ]);

        return compact('user', 'period', 'workload', 'group', 'record');
    }

    public function test_chart_data_reflects_persisted_entries(): void
    {
        ['record' => $record, 'workload' => $workload, 'group' => $group] = $this->createChartContext();

        WorkEntry::create([
            'work_record_id' => $record->id,
            'workload_id' => $workload->id,
            'detail' => 'รายละเอียดทดสอบ chart data',
            'hours' => 3,
            'sort_order' => 1,
        ]);

        $data = app(BuildWorkRecordChartData::class)($record->fresh());

        $this->assertTrue($data['hasEntries']);
        $this->assertSame(['1 '.$group->name], $data['scoreByGroup']['labels']);
        $this->assertSame([30.0], $data['scoreByGroup']['earned']);
        $this->assertSame([100.0], $data['scoreByGroup']['max']);
        $this->assertSame(['1 '.$group->name], $data['hoursByGroup']['labels']);
        $this->assertSame([3.0], $data['hoursByGroup']['values']);
        $this->assertSame(['1 '.$group->name], $data['hoursRadar']['labels']);
        $this->assertSame([3.0], $data['hoursRadar']['values']);
        $this->assertSame(1, $data['progress']['filled']);
        $this->assertSame(0, $data['progress']['empty']);
        $this->assertSame(100, $data['progress']['percent']);
    }

    public function test_chart_data_empty_when_no_entries(): void
    {
        ['record' => $record, 'group' => $group] = $this->createChartContext();

        $data = app(BuildWorkRecordChartData::class)($record);

        $this->assertFalse($data['hasEntries']);
        $this->assertSame([], $data['hoursByGroup']['values']);
        $this->assertSame(['1 '.$group->name], $data['hoursRadar']['labels']);
        $this->assertSame([0.0], $data['hoursRadar']['values']);
        $this->assertSame(0, $data['progress']['filled']);
    }

    public function test_empty_chart_data_includes_hours_radar(): void
    {
        $data = app(BuildWorkRecordChartData::class)->empty();

        $this->assertSame(['labels' => [], 'values' => []], $data['hoursRadar']);
    }
}
