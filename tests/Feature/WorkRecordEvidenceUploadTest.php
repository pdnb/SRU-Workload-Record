<?php

namespace Tests\Feature;

use App\Enums\EvaluationPeriodNumber;
use App\Enums\PeriodStatus;
use App\Enums\UserRole;
use App\Enums\WorkRecordStatus;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class WorkRecordEvidenceUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_multiple_file_evidence_when_saving_a_work_entry(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['role' => UserRole::Staff]);

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
            'sort_order' => 1,
        ]);

        $files = [
            UploadedFile::fake()->create('evidence-one.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('evidence-two.pdf', 100, 'application/pdf'),
        ];

        Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2)
            ->call('openEntryModal', $workload->id)
            ->call('setEvidenceType', 'file')
            ->assertSet('modalEntry.evidence_type', 'file')
            ->set('modalEntry.detail', 'รายละเอียดการดำเนินงานทดสอบอัปโหลดไฟล์หลายไฟล์')
            ->set('evidenceUploads', $files)
            ->call('saveModalEntry')
            ->assertHasNoErrors();

        $record = $user->workRecords()->first();

        $this->assertNotNull($record);
        $this->assertSame(WorkRecordStatus::Draft, $record->status);

        $entry = $record->workEntries()->first();

        $this->assertNotNull($entry);
        $this->assertSame('file', $entry->evidence_type?->value);
        $this->assertCount(2, $entry->evidenceFilePaths());

        foreach ($entry->evidenceFilePaths() as $path) {
            $this->assertStringStartsWith('work-evidence/'.$record->id.'/', $path);
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_step2_master_detail_selects_workload_and_filters_entries(): void
    {
        $user = User::factory()->create(['role' => UserRole::Staff]);

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

        $workloadA = Workload::create([
            'workload_main_id' => $main->id,
            'name' => 'ภาระงาน A',
            'sort_order' => 1,
        ]);

        $workloadB = Workload::create([
            'workload_main_id' => $main->id,
            'name' => 'ภาระงาน B',
            'sort_order' => 2,
        ]);

        Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->call('goToStep', 2)
            ->assertSet('selectedWorkloadId', $workloadA->id)
            ->call('selectWorkload', $workloadB->id)
            ->assertSet('selectedWorkloadId', $workloadB->id)
            ->call('openEntryModal', $workloadA->id)
            ->set('modalEntry.detail', 'รายละเอียดการดำเนินงานภาระงาน A สำหรับทดสอบกรอง')
            ->call('saveModalEntry')
            ->assertHasNoErrors()
            ->call('setWorkloadFilter', 'filled')
            ->assertSet('workloadFilter', 'filled')
            ->assertSet('selectedWorkloadId', $workloadA->id)
            ->call('setWorkloadFilter', 'empty')
            ->assertSet('selectedWorkloadId', $workloadB->id);

        $record = $user->workRecords()->first();
        $this->assertNotNull($record);
        $this->assertSame(1, $record->workEntries()->count());
        $this->assertSame($workloadA->id, $record->workEntries()->first()->workload_id);
    }

    public function test_step2_displays_earned_score_for_workload_entries(): void
    {
        $user = User::factory()->create(['role' => UserRole::Staff]);

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

        Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->call('goToStep', 2)
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', 'รายละเอียดการดำเนินงานทดสอบคะแนนที่ได้')
            ->set('modalEntry.hours', '3')
            ->call('saveModalEntry')
            ->assertHasNoErrors()
            ->assertSee('ได้ 30.00 คะแนน')
            ->assertSee('30.00')
            ->assertSee('30.00 / 100.00 คะแนน')
            ->call('goToStep', 3)
            ->assertSee('คะแนนที่ได้')
            ->assertSee('30.00 / 100.00');
    }
}
