<?php

namespace Tests\Feature;

use App\Actions\SyncCollaboratorWorkEntries;
use App\Enums\EvaluationPeriodNumber;
use App\Enums\PeriodStatus;
use App\Enums\UserRole;
use App\Enums\WorkRecordStatus;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use App\Models\Position;
use App\Models\User;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use App\Models\WorkloadStructure;
use App\Models\WorkRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MultiPositionWorkloadStructureTest extends TestCase
{
    use RefreshDatabase;

    private FiscalYear $fiscalYear;

    private EvaluationPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fiscalYear = FiscalYear::create(['year' => 2569, 'is_active' => true]);
        $this->period = EvaluationPeriod::create([
            'fiscal_year_id' => $this->fiscalYear->id,
            'period' => EvaluationPeriodNumber::One,
            'label' => 'รอบที่ 1',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'status' => PeriodStatus::Open,
        ]);
    }

    /**
     * @return array{structure: WorkloadStructure, group: WorkloadGroup, workload: Workload}
     */
    private function structureWithWorkload(Position $position, string $label, float $groupMax, float $score = 1): array
    {
        $structure = $this->structureFor($this->fiscalYear, $position);

        $group = WorkloadGroup::create([
            'workload_structure_id' => $structure->id,
            'name' => "กลุ่ม {$label}",
            'max_score' => $groupMax,
            'sort_order' => 1,
        ]);
        $main = WorkloadMain::create(['workload_group_id' => $group->id, 'name' => "หมวด {$label}", 'sort_order' => 1]);
        $workload = Workload::create(['workload_main_id' => $main->id, 'name' => "ภาระงาน {$label}", 'score' => $score, 'sort_order' => 1]);

        return compact('structure', 'group', 'workload');
    }

    private function staff(Position $position, string $name = 'บุคลากร'): User
    {
        return User::factory()->create(['role' => UserRole::Staff, 'position_id' => $position->id, 'name' => $name]);
    }

    public function test_each_position_sees_only_its_own_structure(): void
    {
        $computer = $this->defaultPosition();
        $finance = Position::factory()->create(['code' => '0501', 'name' => 'นักวิชาการเงินและบัญชี']);

        $this->structureWithWorkload($computer, 'คอมพิวเตอร์', 50);
        $this->structureWithWorkload($finance, 'การเงิน', 70);

        Livewire::actingAs($this->staff($computer))
            ->test('pages::staff.work-record-form')
            ->call('selectPeriod', $this->period->id)
            ->call('goToStep', 2)
            ->assertSet('step', 2)
            ->assertSee('ภาระงาน คอมพิวเตอร์')
            ->assertDontSee('ภาระงาน การเงิน');

        Livewire::actingAs($this->staff($finance))
            ->test('pages::staff.work-record-form')
            ->call('selectPeriod', $this->period->id)
            ->call('goToStep', 2)
            ->assertSee('ภาระงาน การเงิน')
            ->assertDontSee('ภาระงาน คอมพิวเตอร์');
    }

    public function test_new_record_snapshots_the_structure(): void
    {
        $position = $this->defaultPosition();
        ['structure' => $structure, 'workload' => $workload] = $this->structureWithWorkload($position, 'ก', 50);
        $user = $this->staff($position);

        Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->call('selectPeriod', $this->period->id)
            ->call('goToStep', 2)
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', 'รายละเอียดผลงานเพื่อทดสอบ snapshot โครงสร้าง')
            ->set('modalEntry.hours', 1)
            ->call('saveModalEntry')
            ->assertHasNoErrors();

        $this->assertSame($structure->id, $user->workRecords()->first()->workload_structure_id);
    }

    public function test_maximum_score_is_per_structure(): void
    {
        $computer = $this->defaultPosition();
        $finance = Position::factory()->create();

        ['structure' => $computerStructure] = $this->structureWithWorkload($computer, 'ก', 50);
        ['structure' => $financeStructure] = $this->structureWithWorkload($finance, 'ข', 70);

        $computerRecord = WorkRecord::create([
            'user_id' => $this->staff($computer)->id,
            'evaluation_period_id' => $this->period->id,
            'workload_structure_id' => $computerStructure->id,
            'status' => WorkRecordStatus::Draft,
        ]);
        $financeRecord = WorkRecord::create([
            'user_id' => $this->staff($finance)->id,
            'evaluation_period_id' => $this->period->id,
            'workload_structure_id' => $financeStructure->id,
            'status' => WorkRecordStatus::Draft,
        ]);

        $this->assertSame(50.0, $computerRecord->scoreSummary()->maximum);
        $this->assertSame(70.0, $financeRecord->scoreSummary()->maximum);
    }

    public function test_record_keeps_its_structure_after_the_user_changes_position(): void
    {
        $computer = $this->defaultPosition();
        $finance = Position::factory()->create();

        ['structure' => $computerStructure, 'workload' => $computerWorkload] = $this->structureWithWorkload($computer, 'คอมพิวเตอร์', 50);
        $this->structureWithWorkload($finance, 'การเงิน', 70);

        $user = $this->staff($computer);
        $record = WorkRecord::create([
            'user_id' => $user->id,
            'evaluation_period_id' => $this->period->id,
            'workload_structure_id' => $computerStructure->id,
            'status' => WorkRecordStatus::Draft,
        ]);
        $record->workEntries()->create(['workload_id' => $computerWorkload->id, 'detail' => 'งานเดิม', 'hours' => 2, 'sort_order' => 0]);

        $user->update(['position_id' => $finance->id]);

        $this->assertSame(50.0, $record->fresh()->scoreSummary()->maximum);
        $this->assertSame(2.0, $record->fresh()->scoreSummary()->earned);

        Livewire::actingAs($user->fresh())
            ->test('pages::staff.work-record-form', ['record' => $record])
            ->assertSee('ภาระงาน คอมพิวเตอร์')
            ->assertDontSee('ภาระงาน การเงิน');
    }

    public function test_user_without_position_cannot_start_a_record(): void
    {
        $this->structureWithWorkload($this->defaultPosition(), 'ก', 50);
        $user = User::factory()->create(['role' => UserRole::Staff]);

        Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->call('selectPeriod', $this->period->id)
            ->assertSee('ยังไม่ได้กำหนดตำแหน่งของคุณ')
            ->call('goToStep', 2)
            ->assertSet('step', 1)
            ->call('saveDraft');

        $this->assertSame(0, WorkRecord::count());
    }

    public function test_position_without_structure_for_the_year_cannot_start_a_record(): void
    {
        $user = $this->staff(Position::factory()->create());

        Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->call('selectPeriod', $this->period->id)
            ->assertSee('ยังไม่มีโครงสร้างภาระงานสำหรับตำแหน่งของคุณ')
            ->call('goToStep', 2)
            ->assertSet('step', 1);
    }

    public function test_collaborator_mirroring_skips_collaborators_with_another_structure(): void
    {
        $computer = $this->defaultPosition();
        $finance = Position::factory()->create();

        ['structure' => $computerStructure, 'workload' => $workload] = $this->structureWithWorkload($computer, 'ก', 50);
        $this->structureWithWorkload($finance, 'ข', 70);

        $owner = $this->staff($computer, 'เจ้าของ');
        $sameStructure = $this->staff($computer, 'ตำแหน่งเดียวกัน');
        $otherStructure = $this->staff($finance, 'ต่างตำแหน่ง');

        $record = WorkRecord::create([
            'user_id' => $owner->id,
            'evaluation_period_id' => $this->period->id,
            'workload_structure_id' => $computerStructure->id,
            'status' => WorkRecordStatus::Draft,
        ]);
        $entry = $record->workEntries()->create(['workload_id' => $workload->id, 'detail' => 'งานร่วม', 'hours' => 1, 'sort_order' => 0]);

        $result = app(SyncCollaboratorWorkEntries::class)($entry, [$sameStructure->id, $otherStructure->id]);

        $this->assertSame([$sameStructure->id], $result['synced']);
        $this->assertSame([['id' => $otherStructure->id, 'name' => 'ต่างตำแหน่ง']], $result['mismatched']);
        $this->assertSame($computerStructure->id, $sameStructure->workRecords()->first()->workload_structure_id);
        $this->assertSame(0, $otherStructure->workRecords()->count());
    }

    public function test_collaborator_search_only_lists_same_position(): void
    {
        $computer = $this->defaultPosition();
        $finance = Position::factory()->create();
        ['workload' => $workload] = $this->structureWithWorkload($computer, 'ก', 50);

        $this->staff($computer, 'สมชาย คอมพิวเตอร์');
        $this->staff($finance, 'สมศรี การเงิน');

        Livewire::actingAs($this->staff($computer, 'เจ้าของ'))
            ->test('pages::staff.work-record-form')
            ->call('selectPeriod', $this->period->id)
            ->call('goToStep', 2)
            ->call('openEntryModal', $workload->id)
            ->set('collaboratorSearch', 'สม')
            ->assertSee('สมชาย คอมพิวเตอร์')
            ->assertDontSee('สมศรี การเงิน');
    }

    public function test_deactivated_workloads_are_hidden_unless_already_used(): void
    {
        $position = $this->defaultPosition();
        ['structure' => $structure, 'workload' => $used] = $this->structureWithWorkload($position, 'ใช้แล้ว', 50);
        $unused = Workload::create([
            'workload_main_id' => $used->workload_main_id,
            'name' => 'ภาระงาน ยกเลิกแล้ว',
            'score' => 1,
            'sort_order' => 2,
            'is_active' => false,
        ]);
        $used->update(['is_active' => false]);

        $user = $this->staff($position);
        $record = WorkRecord::create([
            'user_id' => $user->id,
            'evaluation_period_id' => $this->period->id,
            'workload_structure_id' => $structure->id,
            'status' => WorkRecordStatus::Draft,
        ]);
        $record->workEntries()->create(['workload_id' => $used->id, 'detail' => 'งานเดิม', 'hours' => 1, 'sort_order' => 0]);

        Livewire::actingAs($user)
            ->test('pages::staff.work-record-form', ['record' => $record])
            ->assertSee('ภาระงาน ใช้แล้ว')
            ->assertDontSee($unused->name);
    }
}
