<?php

namespace Tests\Feature;

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
use App\Models\WorkloadShare;
use App\Models\WorkRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class WithdrawWorkRecordTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{owner: User, otherStaff: User, record: WorkRecord, period: EvaluationPeriod}
     */
    private function createSubmittedRecord(): array
    {
        $owner = User::factory()->create(['position_id' => $this->defaultPosition()->id, 'role' => UserRole::Staff]);
        $otherStaff = User::factory()->create(['position_id' => $this->defaultPosition()->id, 'role' => UserRole::Staff]);

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
            'sort_order' => 1,
        ]);

        $record = WorkRecord::create([
            'user_id' => $owner->id,
            'evaluation_period_id' => $period->id,
            'status' => WorkRecordStatus::Submitted,
            'submitted_at' => now()->subDay(),
        ]);

        WorkEntry::create([
            'work_record_id' => $record->id,
            'workload_id' => $workload->id,
            'detail' => 'รายละเอียดผลงานทดสอบการถอนการส่ง',
            'sort_order' => 1,
        ]);

        return compact('owner', 'otherStaff', 'record', 'period');
    }

    public function test_owner_can_withdraw_submitted_record_in_open_period(): void
    {
        $data = $this->createSubmittedRecord();

        Livewire::actingAs($data['owner'])
            ->test('pages::staff.work-record-form', ['record' => $data['record']])
            ->call('withdraw')
            ->assertRedirect(route('staff.records.edit', $data['record']));

        $data['record']->refresh();

        $this->assertSame(WorkRecordStatus::Draft, $data['record']->status);
        $this->assertNull($data['record']->submitted_at);
    }

    public function test_other_staff_cannot_withdraw_record(): void
    {
        $data = $this->createSubmittedRecord();

        $this->actingAs($data['otherStaff'])
            ->get(route('staff.records.show', $data['record']))
            ->assertForbidden();

        $this->assertFalse(Gate::forUser($data['otherStaff'])->allows('withdraw', $data['record']));
    }

    public function test_cannot_withdraw_when_period_is_closed(): void
    {
        $data = $this->createSubmittedRecord();
        $data['period']->update(['status' => PeriodStatus::Closed]);

        Livewire::actingAs($data['owner'])
            ->test('pages::staff.work-record-form', ['record' => $data['record']])
            ->call('withdraw')
            ->assertForbidden();
    }

    public function test_cannot_withdraw_approved_record(): void
    {
        $data = $this->createSubmittedRecord();
        $data['record']->update([
            'status' => WorkRecordStatus::Approved,
            'approved_at' => now(),
        ]);

        Livewire::actingAs($data['owner'])
            ->test('pages::staff.work-record-form', ['record' => $data['record']])
            ->call('withdraw')
            ->assertForbidden();
    }

    public function test_withdraw_blocks_public_share_until_resubmitted(): void
    {
        $data = $this->createSubmittedRecord();

        $share = WorkloadShare::create([
            'work_record_id' => $data['record']->id,
            'workload_id' => $data['record']->workEntries()->value('workload_id'),
            'token' => (string) str()->uuid(),
            'enabled_at' => now(),
        ]);

        $this->get(route('share.workload.show', $share->token))->assertOk();

        Livewire::actingAs($data['owner'])
            ->test('pages::staff.work-record-form', ['record' => $data['record']])
            ->call('withdraw')
            ->assertRedirect(route('staff.records.edit', $data['record']));

        $this->get(route('share.workload.show', $share->token))->assertNotFound();

        $data['record']->refresh()->update([
            'status' => WorkRecordStatus::Submitted,
            'submitted_at' => now(),
        ]);

        $this->get(route('share.workload.show', $share->token))->assertOk();
    }
}
