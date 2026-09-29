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

class WorkEntryCollaboratorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{
     *     owner: User,
     *     collabOne: User,
     *     collabTwo: User,
     *     period: EvaluationPeriod,
     *     workload: Workload,
     * }
     */
    private function createCollaboratorContext(): array
    {
        $owner = User::factory()->create(['role' => UserRole::Staff, 'name' => 'เจ้าของบันทึก']);
        $collabOne = User::factory()->create(['role' => UserRole::Staff, 'name' => 'ผู้ร่วมหนึ่ง']);
        $collabTwo = User::factory()->create(['role' => UserRole::Staff, 'name' => 'ผู้ร่วมสอง']);

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
            'fiscal_year_id' => $fiscalYear->id,
            'name' => 'กลุ่มทดสอบ',
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
            'score' => 5,
            'sort_order' => 1,
        ]);

        return compact('owner', 'collabOne', 'collabTwo', 'period', 'workload');
    }

    public function test_saving_entry_with_collaborators_creates_mirror_entries_with_full_hours(): void
    {
        [
            'owner' => $owner,
            'collabOne' => $collabOne,
            'collabTwo' => $collabTwo,
            'period' => $period,
            'workload' => $workload,
        ] = $this->createCollaboratorContext();

        Livewire::actingAs($owner)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2)
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', 'รายละเอียดผลงานร่วมกันทดสอบระบบผู้ร่วมงาน')
            ->set('modalEntry.hours', 2)
            ->set('modalEntry.collaborator_ids', [$collabOne->id, $collabTwo->id])
            ->call('saveModalEntry')
            ->assertHasNoErrors();

        $ownerRecord = $owner->workRecords()->first();
        $sourceEntry = $ownerRecord->workEntries()->whereNull('source_work_entry_id')->first();

        $this->assertNotNull($sourceEntry);
        $this->assertSame('2.0', (string) $sourceEntry->hours);
        $this->assertCount(2, $sourceEntry->mirroredEntries);

        foreach ([$collabOne, $collabTwo] as $collaborator) {
            $record = $collaborator->workRecords()
                ->where('evaluation_period_id', $period->id)
                ->first();

            $this->assertNotNull($record);
            $this->assertSame(WorkRecordStatus::Draft, $record->status);

            $mirror = $record->workEntries()->where('source_work_entry_id', $sourceEntry->id)->first();

            $this->assertNotNull($mirror);
            $this->assertSame($sourceEntry->detail, $mirror->detail);
            $this->assertSame('2.0', (string) $mirror->hours);
        }
    }

    public function test_frequent_collaborators_are_ranked_by_usage_and_exclude_selected(): void
    {
        [
            'owner' => $owner,
            'collabOne' => $collabOne,
            'collabTwo' => $collabTwo,
            'period' => $period,
            'workload' => $workload,
        ] = $this->createCollaboratorContext();

        $component = Livewire::actingAs($owner)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2);

        foreach ([[$collabOne->id, $collabTwo->id], [$collabTwo->id]] as $ids) {
            $component
                ->call('openEntryModal', $workload->id)
                ->set('modalEntry.detail', 'รายละเอียดผลงานร่วมกันทดสอบรายชื่อที่ใช้บ่อย')
                ->set('modalEntry.collaborator_ids', $ids)
                ->call('saveModalEntry')
                ->assertHasNoErrors();
        }

        $component->call('openEntryModal', $workload->id);

        $this->assertSame(
            [$collabTwo->id, $collabOne->id],
            $component->instance()->frequentCollaborators->pluck('id')->all(),
        );

        $component
            ->assertSee('ใช้บ่อย:')
            ->call('addCollaborator', $collabTwo->id);

        $this->assertSame(
            [$collabOne->id],
            $component->instance()->frequentCollaborators->pluck('id')->all(),
        );
    }

    public function test_collaborator_score_includes_mirrored_entry(): void
    {
        [
            'owner' => $owner,
            'collabOne' => $collabOne,
            'period' => $period,
            'workload' => $workload,
        ] = $this->createCollaboratorContext();

        Livewire::actingAs($owner)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2)
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', 'รายละเอียดผลงานร่วมกันทดสอบคะแนนผู้ร่วมงาน')
            ->set('modalEntry.hours', 2)
            ->set('modalEntry.collaborator_ids', [$collabOne->id])
            ->call('saveModalEntry');

        $collabRecord = $collabOne->workRecords()->where('evaluation_period_id', $period->id)->first();

        $summary = app(CalculateWorkRecordScore::class)($collabRecord);

        $this->assertSame(10.0, $summary->earned);
    }

    public function test_collaborator_sees_mirror_entry_as_readonly_in_form(): void
    {
        [
            'owner' => $owner,
            'collabOne' => $collabOne,
            'period' => $period,
            'workload' => $workload,
        ] = $this->createCollaboratorContext();

        Livewire::actingAs($owner)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2)
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', 'รายละเอียดผลงานร่วมกันทดสอบการแสดงผลแบบอ่านอย่างเดียว')
            ->set('modalEntry.hours', 1)
            ->set('modalEntry.collaborator_ids', [$collabOne->id])
            ->call('saveModalEntry');

        $collabRecord = $collabOne->workRecords()->where('evaluation_period_id', $period->id)->first();

        Livewire::actingAs($collabOne)
            ->test('pages::staff.work-record-form', ['record' => $collabRecord])
            ->assertSee('ผลงานร่วมจาก เจ้าของบันทึก')
            ->assertSee('รายละเอียดผลงานร่วมกันทดสอบการแสดงผลแบบอ่านอย่างเดียว')
            ->call('openEntryModal', $workload->id, 0)
            ->assertSet('modalReadOnly', true);
    }

    public function test_owner_update_syncs_mirror_details(): void
    {
        [
            'owner' => $owner,
            'collabOne' => $collabOne,
            'period' => $period,
            'workload' => $workload,
        ] = $this->createCollaboratorContext();

        $component = Livewire::actingAs($owner)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2)
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', 'รายละเอียดผลงานร่วมกันทดสอบการอัปเดตข้อมูลเริ่มต้น')
            ->set('modalEntry.hours', 1)
            ->set('modalEntry.collaborator_ids', [$collabOne->id])
            ->call('saveModalEntry');

        $ownerRecord = $owner->workRecords()->first();

        $component
            ->call('openEntryModal', $workload->id, 0)
            ->set('modalEntry.detail', 'รายละเอียดผลงานร่วมกันทดสอบการอัปเดตข้อมูลใหม่แล้ว')
            ->set('modalEntry.hours', 3)
            ->call('saveModalEntry');

        $mirror = WorkEntry::query()
            ->where('source_work_entry_id', $ownerRecord->workEntries()->first()->id)
            ->first();

        $this->assertSame('รายละเอียดผลงานร่วมกันทดสอบการอัปเดตข้อมูลใหม่แล้ว', $mirror->detail);
        $this->assertSame('3.0', (string) $mirror->hours);
    }

    public function test_deleting_source_entry_removes_mirrors(): void
    {
        [
            'owner' => $owner,
            'collabOne' => $collabOne,
            'period' => $period,
            'workload' => $workload,
        ] = $this->createCollaboratorContext();

        Livewire::actingAs($owner)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2)
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', 'รายละเอียดผลงานร่วมกันทดสอบการลบรายการต้นทาง')
            ->set('modalEntry.hours', 1)
            ->set('modalEntry.collaborator_ids', [$collabOne->id])
            ->call('saveModalEntry')
            ->call('openEntryModal', $workload->id, 0)
            ->call('openDeleteEntryConfirm')
            ->call('confirmDeleteModalEntry');

        $this->assertSame(0, WorkEntry::query()->whereNotNull('source_work_entry_id')->count());
        $this->assertSame(0, $collabOne->workRecords()->first()->workEntries()->count());
    }

    public function test_skips_collaborator_with_submitted_record_and_shows_warning(): void
    {
        [
            'owner' => $owner,
            'collabOne' => $collabOne,
            'collabTwo' => $collabTwo,
            'period' => $period,
            'workload' => $workload,
        ] = $this->createCollaboratorContext();

        WorkRecord::create([
            'user_id' => $collabOne->id,
            'evaluation_period_id' => $period->id,
            'status' => WorkRecordStatus::Submitted,
            'submitted_at' => now(),
        ]);

        Livewire::actingAs($owner)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2)
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', 'รายละเอียดผลงานร่วมกันทดสอบข้ามผู้ส่งบันทึกแล้ว')
            ->set('modalEntry.hours', 1)
            ->set('modalEntry.collaborator_ids', [$collabOne->id, $collabTwo->id])
            ->call('saveModalEntry')
            ->assertHasNoErrors();

        $sourceEntry = $owner->workRecords()->first()->workEntries()->whereNull('source_work_entry_id')->first();

        $this->assertCount(1, $sourceEntry->mirroredEntries);
        $this->assertTrue(
            $collabTwo->workRecords()->where('evaluation_period_id', $period->id)->exists()
        );
        $this->assertFalse(
            WorkEntry::query()
                ->where('source_work_entry_id', $sourceEntry->id)
                ->where('work_record_id', $collabOne->workRecords()->first()->id)
                ->exists()
        );
    }

    public function test_cannot_add_self_as_collaborator(): void
    {
        [
            'owner' => $owner,
            'period' => $period,
            'workload' => $workload,
        ] = $this->createCollaboratorContext();

        Livewire::actingAs($owner)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2)
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', 'รายละเอียดผลงานร่วมกันทดสอบห้ามเลือกตัวเอง')
            ->set('modalEntry.hours', 1)
            ->set('modalEntry.collaborator_ids', [$owner->id])
            ->call('saveModalEntry')
            ->assertHasErrors(['modalEntry.collaborator_ids.0']);
    }

    public function test_cannot_add_non_staff_as_collaborator(): void
    {
        [
            'owner' => $owner,
            'period' => $period,
            'workload' => $workload,
        ] = $this->createCollaboratorContext();

        $hrUser = User::factory()->create(['role' => UserRole::Hr]);

        Livewire::actingAs($owner)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2)
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', 'รายละเอียดผลงานร่วมกันทดสอบห้ามเลือกไม่ใช่ staff')
            ->set('modalEntry.hours', 1)
            ->set('modalEntry.collaborator_ids', [$hrUser->id])
            ->call('saveModalEntry')
            ->assertHasErrors(['modalEntry.collaborator_ids.0']);
    }
}
