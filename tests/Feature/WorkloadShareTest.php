<?php

namespace Tests\Feature;

use App\Actions\EnableAllWorkloadShares;
use App\Enums\EvaluationPeriodNumber;
use App\Enums\EvidenceType;
use App\Enums\PeriodStatus;
use App\Enums\UserRole;
use App\Enums\WorkRecordStatus;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\WorkEntry;
use App\Models\WorkEntryEvidence;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use App\Models\WorkloadShare;
use App\Models\WorkRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class WorkloadShareTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{
     *     owner: User,
     *     otherStaff: User,
     *     record: WorkRecord,
     *     workload: Workload,
     *     entry: WorkEntry,
     *     evidence: WorkEntryEvidence,
     *     path: string,
     * }
     */
    private function createSubmittedRecordWithEvidence(): array
    {
        Storage::fake('public');

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
            'score' => 2.5,
            'sort_order' => 1,
        ]);

        $otherWorkload = Workload::create([
            'workload_main_id' => $main->id,
            'name' => 'ภาระงานอื่น',
            'sort_order' => 2,
        ]);

        $record = WorkRecord::create([
            'user_id' => $owner->id,
            'evaluation_period_id' => $period->id,
            'status' => WorkRecordStatus::Submitted,
            'submitted_at' => now(),
        ]);

        $path = 'work-evidence/'.$record->id.'/evidence.pdf';
        Storage::disk('public')->put($path, 'test file contents');

        $entry = WorkEntry::create([
            'work_record_id' => $record->id,
            'workload_id' => $workload->id,
            'detail' => 'รายละเอียดผลงานทดสอบสำหรับแชร์ลิงก์',
            'result' => 'ผลลัพธ์ทดสอบ',
            'hours' => 2,
            'sort_order' => 1,
        ]);

        WorkEntry::create([
            'work_record_id' => $record->id,
            'workload_id' => $otherWorkload->id,
            'detail' => 'ผลงานภาระงานอื่นที่ไม่ควรแสดงในหน้าแชร์',
            'sort_order' => 1,
        ]);

        $evidence = WorkEntryEvidence::create([
            'work_entry_id' => $entry->id,
            'type' => EvidenceType::File,
            'value' => $path,
            'filename' => 'evidence.pdf',
            'sort_order' => 1,
        ]);

        return compact('owner', 'otherStaff', 'record', 'workload', 'entry', 'evidence', 'path');
    }

    public function test_owner_can_enable_share_and_guest_can_view_public_summary(): void
    {
        $data = $this->createSubmittedRecordWithEvidence();

        Livewire::actingAs($data['owner'])
            ->test('pages::staff.work-record-form', ['record' => $data['record']])
            ->set('step', 2)
            ->set('selectedWorkloadId', $data['workload']->id)
            ->call('enableWorkloadShare')
            ->assertHasNoErrors();

        $share = WorkloadShare::query()->first();

        $this->assertNotNull($share);
        $this->assertNotNull($share->enabled_at);

        $response = $this->get(route('share.workload.show', $share->token));

        $response->assertOk();
        $response->assertSee('รายละเอียดผลงานทดสอบสำหรับแชร์ลิงก์');
        $response->assertSee('ผลลัพธ์ทดสอบ');
        $response->assertSee('ภาระงานทดสอบ');
        $response->assertDontSee('ผลงานภาระงานอื่นที่ไม่ควรแสดงในหน้าแชร์');
    }

    public function test_disabled_or_invalid_token_returns_not_found(): void
    {
        $data = $this->createSubmittedRecordWithEvidence();

        $share = WorkloadShare::create([
            'work_record_id' => $data['record']->id,
            'workload_id' => $data['workload']->id,
            'token' => (string) str()->uuid(),
            'enabled_at' => null,
        ]);

        $this->get(route('share.workload.show', $share->token))->assertNotFound();
        $this->get(route('share.workload.show', (string) str()->uuid()))->assertNotFound();
    }

    public function test_enabled_share_for_draft_record_returns_not_found(): void
    {
        $data = $this->createSubmittedRecordWithEvidence();
        $data['record']->update(['status' => WorkRecordStatus::Draft, 'submitted_at' => null]);

        $share = WorkloadShare::create([
            'work_record_id' => $data['record']->id,
            'workload_id' => $data['workload']->id,
            'token' => (string) str()->uuid(),
            'enabled_at' => now(),
        ]);

        $this->get(route('share.workload.show', $share->token))->assertNotFound();
    }

    public function test_public_evidence_preview_and_download_require_valid_token(): void
    {
        $data = $this->createSubmittedRecordWithEvidence();

        $share = WorkloadShare::create([
            'work_record_id' => $data['record']->id,
            'workload_id' => $data['workload']->id,
            'token' => (string) str()->uuid(),
            'enabled_at' => now(),
        ]);

        $this->get(route('share.workload.evidence.preview', [
            'token' => $share->token,
            'evidence' => $data['evidence'],
        ]))->assertOk();

        $this->get(route('share.workload.evidence.download', [
            'token' => $share->token,
            'evidence' => $data['evidence'],
        ]))->assertOk();

        $this->get(route('share.workload.evidence.preview', [
            'token' => (string) str()->uuid(),
            'evidence' => $data['evidence'],
        ]))->assertNotFound();
    }

    public function test_evidence_from_other_workload_is_not_accessible_via_share_token(): void
    {
        $data = $this->createSubmittedRecordWithEvidence();

        $otherEntry = WorkEntry::query()
            ->where('work_record_id', $data['record']->id)
            ->where('workload_id', '!=', $data['workload']->id)
            ->first();

        $otherEvidence = WorkEntryEvidence::create([
            'work_entry_id' => $otherEntry->id,
            'type' => EvidenceType::Url,
            'value' => 'https://example.com/other',
            'sort_order' => 1,
        ]);

        $share = WorkloadShare::create([
            'work_record_id' => $data['record']->id,
            'workload_id' => $data['workload']->id,
            'token' => (string) str()->uuid(),
            'enabled_at' => now(),
        ]);

        $this->get(route('share.workload.evidence.download', [
            'token' => $share->token,
            'evidence' => $otherEvidence,
        ]))->assertNotFound();
    }

    public function test_other_staff_cannot_enable_share_for_another_users_record(): void
    {
        $data = $this->createSubmittedRecordWithEvidence();

        $this->actingAs($data['otherStaff'])
            ->get(route('staff.records.show', $data['record']))
            ->assertForbidden();

        $this->assertFalse(Gate::forUser($data['otherStaff'])->allows(
            'manageRecordWorkload',
            [$data['record'], $data['workload']->id],
        ));

        $this->assertDatabaseCount('workload_shares', 0);
    }

    public function test_draft_record_cannot_be_shared(): void
    {
        $data = $this->createSubmittedRecordWithEvidence();
        $data['record']->update(['status' => WorkRecordStatus::Draft, 'submitted_at' => null]);

        Livewire::actingAs($data['owner'])
            ->test('pages::staff.work-record-form', ['record' => $data['record']])
            ->set('step', 2)
            ->set('selectedWorkloadId', $data['workload']->id)
            ->call('enableWorkloadShare')
            ->assertForbidden();
    }

    public function test_disabling_share_revokes_public_access_and_reenable_generates_new_token(): void
    {
        $data = $this->createSubmittedRecordWithEvidence();

        $share = WorkloadShare::create([
            'work_record_id' => $data['record']->id,
            'workload_id' => $data['workload']->id,
            'token' => (string) str()->uuid(),
            'enabled_at' => now(),
        ]);

        $oldToken = $share->token;

        Livewire::actingAs($data['owner'])
            ->test('pages::staff.work-record-form', ['record' => $data['record']])
            ->set('step', 2)
            ->set('selectedWorkloadId', $data['workload']->id)
            ->call('disableWorkloadShare')
            ->assertHasNoErrors();

        $this->get(route('share.workload.show', $oldToken))->assertNotFound();

        Livewire::actingAs($data['owner'])
            ->test('pages::staff.work-record-form', ['record' => $data['record']])
            ->set('step', 2)
            ->set('selectedWorkloadId', $data['workload']->id)
            ->call('enableWorkloadShare')
            ->assertHasNoErrors();

        $share->refresh();

        $this->assertNotEquals($oldToken, $share->token);
        $this->get(route('share.workload.show', $share->token))->assertOk();
    }

    public function test_copy_share_button_renders_a_valid_alpine_expression(): void
    {
        $data = $this->createSubmittedRecordWithEvidence();

        WorkloadShare::create([
            'work_record_id' => $data['record']->id,
            'workload_id' => $data['workload']->id,
            'token' => (string) str()->uuid(),
            'enabled_at' => now(),
        ]);

        $html = Livewire::actingAs($data['owner'])
            ->test('pages::staff.work-record-form', ['record' => $data['record']])
            ->set('step', 2)
            ->set('selectedWorkloadId', $data['workload']->id)
            ->html();

        $this->assertStringNotContainsString('@js(', $html);
        $this->assertStringContainsString('window.copyText(shareUrl)', $html);
    }

    public function test_submit_enables_share_for_every_workload_with_entries(): void
    {
        $data = $this->createSubmittedRecordWithEvidence();
        $data['record']->update(['status' => WorkRecordStatus::Draft, 'submitted_at' => null]);

        Livewire::actingAs($data['owner'])
            ->test('pages::staff.work-record-form', ['record' => $data['record']])
            ->call('submit')
            ->assertRedirect(route('staff.records.show', $data['record']));

        $shares = WorkloadShare::query()->where('work_record_id', $data['record']->id)->get();

        $this->assertCount(2, $shares);
        $this->assertTrue($shares->every(fn (WorkloadShare $share) => $share->isEnabled()));

        foreach ($shares as $share) {
            $this->get(route('share.workload.show', $share->token))->assertOk();
        }
    }

    public function test_resubmit_after_withdraw_keeps_existing_share_tokens(): void
    {
        $data = $this->createSubmittedRecordWithEvidence();

        $share = WorkloadShare::create([
            'work_record_id' => $data['record']->id,
            'workload_id' => $data['workload']->id,
            'token' => (string) str()->uuid(),
            'enabled_at' => null,
        ]);

        $data['record']->update(['status' => WorkRecordStatus::Draft, 'submitted_at' => null]);

        Livewire::actingAs($data['owner'])
            ->test('pages::staff.work-record-form', ['record' => $data['record']])
            ->call('submit');

        $refreshed = $share->fresh();

        $this->assertSame($share->token, $refreshed->token);
        $this->assertTrue($refreshed->isEnabled());
        $this->get(route('share.workload.show', $share->token))->assertOk();
    }

    public function test_submitted_record_summary_lists_all_share_links(): void
    {
        $data = $this->createSubmittedRecordWithEvidence();

        app(EnableAllWorkloadShares::class)($data['record']);

        $component = Livewire::actingAs($data['owner'])
            ->test('pages::staff.work-record-form', ['record' => $data['record']])
            ->assertSee('ลิงก์สรุปผลงานที่แชร์')
            ->assertSee('คัดลอกทั้งหมด');

        foreach (WorkloadShare::query()->get() as $share) {
            $component->assertSee($share->shareUrl());
        }

        $this->assertCount(2, $component->get('sharedWorkloadLinks'));
    }

    public function test_submitted_record_without_links_can_generate_all(): void
    {
        $data = $this->createSubmittedRecordWithEvidence();

        Livewire::actingAs($data['owner'])
            ->test('pages::staff.work-record-form', ['record' => $data['record']])
            ->assertSee('สร้างลิงก์ทุกภาระงาน')
            ->call('enableAllWorkloadShares')
            ->assertSee('คัดลอกทั้งหมด');

        $this->assertSame(2, WorkloadShare::query()->whereNotNull('enabled_at')->count());
    }

    public function test_draft_record_does_not_show_share_links_card(): void
    {
        $data = $this->createSubmittedRecordWithEvidence();
        $data['record']->update(['status' => WorkRecordStatus::Draft, 'submitted_at' => null]);

        Livewire::actingAs($data['owner'])
            ->test('pages::staff.work-record-form', ['record' => $data['record']])
            ->set('step', 3)
            ->assertDontSee('ลิงก์สรุปผลงานที่แชร์')
            ->call('enableAllWorkloadShares');

        $this->assertDatabaseCount('workload_shares', 0);
    }
}
