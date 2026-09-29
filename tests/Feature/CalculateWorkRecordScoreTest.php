<?php

namespace Tests\Feature;

use App\Actions\CalculateWorkRecordScore;
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
use Livewire\Livewire;
use Tests\TestCase;

class CalculateWorkRecordScoreTest extends TestCase
{
    use RefreshDatabase;

    private function createRecordWithStructure(array $options = []): WorkRecord
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
            'max_score' => $options['group_max_score'] ?? null,
            'sort_order' => 1,
        ]);

        $main = WorkloadMain::create([
            'workload_group_id' => $group->id,
            'name' => 'หมวดทดสอบ',
            'max_score' => $options['main_max_score'] ?? null,
            'sort_order' => 1,
        ]);

        $workload = Workload::create([
            'workload_main_id' => $main->id,
            'name' => 'ภาระงานทดสอบ',
            'score' => $options['workload_score'] ?? null,
            'sort_order' => 1,
        ]);

        $record = WorkRecord::create([
            'user_id' => $user->id,
            'evaluation_period_id' => $period->id,
            'status' => WorkRecordStatus::Draft,
        ]);

        foreach ($options['entries'] ?? [] as $index => $entry) {
            WorkEntry::create([
                'work_record_id' => $record->id,
                'workload_id' => $workload->id,
                'detail' => 'รายละเอียด '.($index + 1),
                'result' => null,
                'hours' => $entry['hours'] ?? 1,
                'sort_order' => $index + 1,
            ]);
        }

        return $record->fresh([
            'workEntries.workload.workloadMain.workloadGroup',
            'evaluationPeriod',
        ]);
    }

    public function test_sums_score_multiplied_by_hours_per_entry(): void
    {
        $record = $this->createRecordWithStructure([
            'workload_score' => 10,
            'entries' => [
                ['hours' => 2],
                ['hours' => 3],
            ],
        ]);

        $summary = app(CalculateWorkRecordScore::class)($record);

        $this->assertSame(50.0, $summary->earned);
        $this->assertNull($summary->maximum);
    }

    public function test_caps_score_at_main_max_score(): void
    {
        $record = $this->createRecordWithStructure([
            'workload_score' => 20,
            'main_max_score' => 50,
            'entries' => [
                ['hours' => 2],
                ['hours' => 2],
            ],
        ]);

        $summary = app(CalculateWorkRecordScore::class)($record);

        $this->assertSame(50.0, $summary->earned);
    }

    public function test_caps_score_at_group_max_score(): void
    {
        $record = $this->createRecordWithStructure([
            'workload_score' => 10,
            'group_max_score' => 30,
            'main_max_score' => 100,
            'entries' => [
                ['hours' => 5],
            ],
        ]);

        $summary = app(CalculateWorkRecordScore::class)($record);

        $this->assertSame(30.0, $summary->earned);
        $this->assertSame(30.0, $summary->maximum);
    }

    public function test_maximum_is_sum_of_group_max_scores_for_fiscal_year(): void
    {
        $record = $this->createRecordWithStructure([
            'workload_score' => 5,
            'group_max_score' => 40,
            'entries' => [
                ['hours' => 1],
            ],
        ]);

        $fiscalYearId = $record->evaluationPeriod->fiscal_year_id;

        WorkloadGroup::create([
            'workload_structure_id' => $this->structureFor($fiscalYearId)->id,
            'name' => 'กลุ่มที่สอง',
            'max_score' => 60,
            'sort_order' => 2,
        ]);

        $summary = app(CalculateWorkRecordScore::class)($record);

        $this->assertSame(5.0, $summary->earned);
        $this->assertSame(100.0, $summary->maximum);
    }

    public function test_null_workload_score_contributes_zero(): void
    {
        $record = $this->createRecordWithStructure([
            'workload_score' => null,
            'entries' => [
                ['hours' => 5],
            ],
        ]);

        $summary = app(CalculateWorkRecordScore::class)($record);

        $this->assertSame(0.0, $summary->earned);
    }

    public function test_zero_hours_contributes_zero(): void
    {
        $record = $this->createRecordWithStructure([
            'workload_score' => 10,
            'entries' => [
                ['hours' => 0],
            ],
        ]);

        $summary = app(CalculateWorkRecordScore::class)($record);

        $this->assertSame(0.0, $summary->earned);
    }

    public function test_record_list_displays_score_column(): void
    {
        $user = User::factory()->create(['position_id' => $this->defaultPosition()->id, 'role' => UserRole::Staff]);

        $record = $this->createRecordWithStructure([
            'workload_score' => 10,
            'group_max_score' => 100,
            'entries' => [
                ['hours' => 2],
            ],
        ]);

        $record->update(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('pages::staff.record-list')
            ->assertSee('คะแนน')
            ->assertSee('20.00 / 100.00');
    }
}
