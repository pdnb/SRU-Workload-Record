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

    /**
     * @return array{user: User, period: EvaluationPeriod, workloadA: Workload, workloadB: Workload}
     */
    private function createWorkRecordContext(): array
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

        return compact('user', 'period', 'workloadA', 'workloadB');
    }

    public function test_stores_multiple_file_evidence_when_saving_a_work_entry(): void
    {
        Storage::fake('public');

        ['user' => $user, 'period' => $period, 'workloadA' => $workload] = $this->createWorkRecordContext();

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

        $entry = $record->workEntries()->with('fileEvidences')->first();

        $this->assertNotNull($entry);
        $this->assertCount(2, $entry->fileEvidences);
        $this->assertSame('evidence-one.pdf', $entry->fileEvidences[0]->filename);
        $this->assertSame('evidence-two.pdf', $entry->fileEvidences[1]->filename);

        foreach ($entry->fileEvidences as $evidence) {
            $this->assertStringStartsWith('work-evidence/'.$record->id.'/', $evidence->value);
            Storage::disk('public')->assertExists($evidence->value);
        }
    }

    public function test_pending_image_upload_shows_thumbnail_preview(): void
    {
        Storage::fake('public');

        ['user' => $user, 'period' => $period, 'workloadA' => $workload] = $this->createWorkRecordContext();

        Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2)
            ->call('openEntryModal', $workload->id)
            ->call('setEvidenceType', 'file')
            ->set('evidenceUploads', [UploadedFile::fake()->image('photo.jpg')])
            ->assertSeeHtml('<img')
            ->assertSeeHtml('/storage/work-evidence/');
    }

    public function test_saving_one_entry_does_not_delete_file_evidence_from_other_entries(): void
    {
        Storage::fake('public');

        ['user' => $user, 'period' => $period, 'workloadA' => $workloadA, 'workloadB' => $workloadB] = $this->createWorkRecordContext();

        $component = Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2);

        $component
            ->call('openEntryModal', $workloadA->id)
            ->call('setEvidenceType', 'file')
            ->set('modalEntry.detail', 'รายละเอียดผลงานภาระงาน A พร้อมไฟล์แนบหลักฐาน')
            ->set('evidenceUploads', [
                UploadedFile::fake()->create('workload-a.pdf', 100, 'application/pdf'),
            ])
            ->call('saveModalEntry')
            ->assertHasNoErrors();

        $record = $user->workRecords()->first();
        $entryA = $record->workEntries()->where('workload_id', $workloadA->id)->with('fileEvidences')->first();

        $this->assertNotNull($entryA);
        $this->assertCount(1, $entryA->fileEvidences);
        $entryAPath = $entryA->fileEvidences->first()->value;

        $component
            ->call('openEntryModal', $workloadB->id)
            ->call('setEvidenceType', 'file')
            ->set('modalEntry.detail', 'รายละเอียดผลงานภาระงาน B พร้อมไฟล์แนบหลักฐาน')
            ->set('evidenceUploads', [
                UploadedFile::fake()->create('workload-b.pdf', 100, 'application/pdf'),
            ])
            ->call('saveModalEntry')
            ->assertHasNoErrors();

        $entryA->refresh()->load('fileEvidences');
        $entryB = $record->workEntries()->where('workload_id', $workloadB->id)->with('fileEvidences')->first();

        $this->assertCount(1, $entryA->fileEvidences);
        $this->assertSame($entryAPath, $entryA->fileEvidences->first()->value);
        Storage::disk('public')->assertExists($entryAPath);

        $this->assertNotNull($entryB);
        $this->assertCount(1, $entryB->fileEvidences);
        $this->assertSame('workload-b.pdf', $entryB->fileEvidences->first()->filename);
    }

    public function test_saving_second_entry_in_same_workload_does_not_delete_first_entry_files(): void
    {
        Storage::fake('public');

        ['user' => $user, 'period' => $period, 'workloadA' => $workload] = $this->createWorkRecordContext();

        $component = Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2);

        $component
            ->call('openEntryModal', $workload->id)
            ->call('setEvidenceType', 'file')
            ->set('modalEntry.detail', 'รายละเอียดผลงานแรกในภาระงานเดียวกันพร้อมไฟล์')
            ->set('evidenceUploads', [
                UploadedFile::fake()->create('entry-one.pdf', 100, 'application/pdf'),
            ])
            ->call('saveModalEntry')
            ->assertHasNoErrors();

        $record = $user->workRecords()->first();
        $firstEntry = $record->workEntries()->orderBy('sort_order')->with('fileEvidences')->first();
        $firstPath = $firstEntry->fileEvidences->first()->value;

        $component
            ->call('openEntryModal', $workload->id)
            ->call('setEvidenceType', 'file')
            ->set('modalEntry.detail', 'รายละเอียดผลงานที่สองในภาระงานเดียวกันพร้อมไฟล์')
            ->set('evidenceUploads', [
                UploadedFile::fake()->create('entry-two.pdf', 100, 'application/pdf'),
            ])
            ->call('saveModalEntry')
            ->assertHasNoErrors();

        $firstEntry->refresh()->load('fileEvidences');
        $allEntries = $record->workEntries()->orderBy('sort_order')->with('fileEvidences')->get();

        $this->assertCount(2, $allEntries);
        $this->assertSame([0, 1], $allEntries->pluck('sort_order')->all());

        $secondEntry = $allEntries->last();

        $this->assertCount(2, $record->workEntries);
        $this->assertCount(1, $firstEntry->fileEvidences);
        $this->assertSame($firstPath, $firstEntry->fileEvidences->first()->value);
        $this->assertCount(1, $secondEntry->fileEvidences);
        $this->assertSame('entry-two.pdf', $secondEntry->fileEvidences->first()->filename);
    }

    public function test_pasted_screenshot_png_is_stored_as_evidence(): void
    {
        Storage::fake('public');

        ['user' => $user, 'period' => $period, 'workloadA' => $workload] = $this->createWorkRecordContext();

        Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2)
            ->call('openEntryModal', $workload->id)
            ->call('setEvidenceType', 'file')
            ->set('modalEntry.detail', 'รายละเอียดผลงานทดสอบการแนบภาพหน้าจอเป็นหลักฐาน')
            ->set('evidenceUploads', [UploadedFile::fake()->image('screenshot-20261001-101500.png')])
            ->assertHasNoErrors()
            ->call('saveModalEntry')
            ->assertHasNoErrors();

        $record = $user->workRecords()->first();
        $evidence = $record->workEntries()->with('fileEvidences')->first()->fileEvidences->sole();

        $this->assertSame('screenshot-20261001-101500.png', $evidence->filename);
        $this->assertStringStartsWith('work-evidence/'.$record->id.'/', $evidence->value);
        Storage::disk('public')->assertExists($evidence->value);
    }

    public function test_rejects_disallowed_file_type_on_upload(): void
    {
        Storage::fake('public');

        ['user' => $user, 'period' => $period, 'workloadA' => $workload] = $this->createWorkRecordContext();

        Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2)
            ->call('openEntryModal', $workload->id)
            ->call('setEvidenceType', 'file')
            ->set('evidenceUploads', [UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload')])
            ->assertHasErrors('evidenceUploads.0')
            ->assertSet('modalEntry.evidence_files', []);

        $this->assertSame([], Storage::disk('public')->allFiles('work-evidence'));
    }

    public function test_file_mode_renders_capture_and_paste_buttons(): void
    {
        ['user' => $user, 'period' => $period, 'workloadA' => $workload] = $this->createWorkRecordContext();

        Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2)
            ->call('openEntryModal', $workload->id)
            ->call('setEvidenceType', 'file')
            ->assertSee('จับภาพหน้าจอ')
            ->assertSee('วางภาพจากคลิปบอร์ด')
            ->assertSeeHtml('x-data="evidenceCapture"');
    }

    public function test_multiple_upload_batches_before_save_keeps_all_files(): void
    {
        Storage::fake('public');

        ['user' => $user, 'period' => $period, 'workloadA' => $workload] = $this->createWorkRecordContext();

        Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2)
            ->call('openEntryModal', $workload->id)
            ->call('setEvidenceType', 'file')
            ->set('modalEntry.detail', 'รายละเอียดผลงานทดสอบการอัปโหลดไฟล์หลายรอบก่อนบันทึก')
            ->set('evidenceUploads', [
                UploadedFile::fake()->create('first-pick.pdf', 100, 'application/pdf'),
            ])
            ->set('evidenceUploads', [
                UploadedFile::fake()->create('second-pick.pdf', 100, 'application/pdf'),
            ])
            ->call('saveModalEntry')
            ->assertHasNoErrors();

        $entry = $user->workRecords()->first()->workEntries()->with('fileEvidences')->first();

        $this->assertCount(2, $entry->fileEvidences);
        $this->assertSame(
            ['first-pick.pdf', 'second-pick.pdf'],
            $entry->fileEvidences->pluck('filename')->all(),
        );
    }

    public function test_adding_more_files_to_existing_entry_preserves_previous_files(): void
    {
        Storage::fake('public');

        ['user' => $user, 'period' => $period, 'workloadA' => $workload] = $this->createWorkRecordContext();

        $component = Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2)
            ->call('openEntryModal', $workload->id)
            ->call('setEvidenceType', 'file')
            ->set('modalEntry.detail', 'รายละเอียดผลงานทดสอบเพิ่มไฟล์หลายรอบ')
            ->set('evidenceUploads', [
                UploadedFile::fake()->create('first-batch.pdf', 100, 'application/pdf'),
            ])
            ->call('saveModalEntry')
            ->assertHasNoErrors();

        $record = $user->workRecords()->first();
        $entry = $record->workEntries()->with('fileEvidences')->first();
        $firstPath = $entry->fileEvidences->first()->value;

        $component
            ->call('openEntryModal', $workload->id, 0)
            ->set('evidenceUploads', [
                UploadedFile::fake()->create('second-batch.pdf', 100, 'application/pdf'),
            ])
            ->call('saveModalEntry')
            ->assertHasNoErrors();

        $entry->refresh()->load('fileEvidences');

        $this->assertCount(2, $entry->fileEvidences);
        $this->assertSame('first-batch.pdf', $entry->fileEvidences[0]->filename);
        $this->assertSame('second-batch.pdf', $entry->fileEvidences[1]->filename);
        $this->assertSame($firstPath, $entry->fileEvidences[0]->value);
        Storage::disk('public')->assertExists($firstPath);
        Storage::disk('public')->assertExists($entry->fileEvidences[1]->value);
    }

    public function test_step2_master_detail_selects_workload_and_filters_entries(): void
    {
        ['user' => $user, 'period' => $period, 'workloadA' => $workloadA, 'workloadB' => $workloadB] = $this->createWorkRecordContext();

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

        Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->call('goToStep', 2)
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', 'รายละเอียดการดำเนินงานทดสอบคะแนนที่ได้')
            ->set('modalEntry.hours', '3')
            ->call('saveModalEntry')
            ->assertHasNoErrors()
            ->assertSee('ได้')
            ->assertSee('30.00 คะแนน')
            ->assertSee('30.00')
            ->call('goToStep', 3)
            ->assertSee('คะแนนที่ได้')
            ->assertSee('30.00');
    }
}
