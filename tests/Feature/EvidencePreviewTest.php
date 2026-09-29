<?php

namespace Tests\Feature;

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
use App\Models\WorkRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EvidencePreviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, evidence: WorkEntryEvidence, path: string}
     */
    private function createFileEvidence(string $filename = 'evidence.pdf'): array
    {
        Storage::fake('public');

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

        $workload = Workload::create([
            'workload_main_id' => $main->id,
            'name' => 'ภาระงานทดสอบ',
            'sort_order' => 1,
        ]);

        $record = WorkRecord::create([
            'user_id' => $user->id,
            'evaluation_period_id' => $period->id,
            'status' => WorkRecordStatus::Draft,
        ]);

        $path = 'work-evidence/'.$record->id.'/'.$filename;
        Storage::disk('public')->put($path, 'test file contents');

        $entry = WorkEntry::create([
            'work_record_id' => $record->id,
            'workload_id' => $workload->id,
            'detail' => 'รายละเอียดการดำเนินงานทดสอบ preview',
            'sort_order' => 1,
        ]);

        $evidence = WorkEntryEvidence::create([
            'work_entry_id' => $entry->id,
            'type' => EvidenceType::File,
            'value' => $path,
            'filename' => $filename,
            'sort_order' => 0,
        ]);

        return compact('user', 'evidence', 'path');
    }

    public function test_owner_can_preview_file_evidence_inline(): void
    {
        ['user' => $user, 'evidence' => $evidence] = $this->createFileEvidence();

        $response = $this->actingAs($user)->get(route('staff.evidence.preview', $evidence));

        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'inline; filename="evidence.pdf"');
    }

    public function test_other_user_cannot_preview_file_evidence(): void
    {
        ['evidence' => $evidence] = $this->createFileEvidence();

        $otherUser = User::factory()->create(['position_id' => $this->defaultPosition()->id, 'role' => UserRole::Staff]);

        $this->actingAs($otherUser)->get(route('staff.evidence.preview', $evidence))->assertForbidden();
    }

    public function test_preview_returns_not_found_for_url_evidence_type(): void
    {
        ['user' => $user, 'evidence' => $fileEvidence] = $this->createFileEvidence();

        $urlEvidence = WorkEntryEvidence::create([
            'work_entry_id' => $fileEvidence->work_entry_id,
            'type' => EvidenceType::Url,
            'value' => 'https://example.com/evidence',
            'filename' => null,
            'sort_order' => 0,
        ]);

        $this->actingAs($user)->get(route('staff.evidence.preview', $urlEvidence))->assertNotFound();
    }

    public function test_preview_returns_not_found_when_file_missing_on_disk(): void
    {
        ['user' => $user, 'evidence' => $evidence, 'path' => $path] = $this->createFileEvidence();

        Storage::disk('public')->delete($path);

        $this->actingAs($user)->get(route('staff.evidence.preview', $evidence))->assertNotFound();
    }

    public function test_is_path_previewable(): void
    {
        $this->assertTrue(WorkEntryEvidence::isPathPreviewable('folder/photo.jpg'));
        $this->assertTrue(WorkEntryEvidence::isPathPreviewable('folder/doc.PDF'));
        $this->assertFalse(WorkEntryEvidence::isPathPreviewable('folder/report.docx'));
    }

    public function test_evidence_preview_route_returns_null_for_non_previewable_files(): void
    {
        ['evidence' => $evidence] = $this->createFileEvidence('report.docx');

        $this->assertNull($evidence->fresh()->previewRoute());
    }

    public function test_evidence_preview_route_returns_preview_url_for_previewable_files(): void
    {
        ['evidence' => $evidence] = $this->createFileEvidence('photo.png');

        $url = $evidence->fresh()->previewRoute();

        $this->assertNotNull($url);
        $this->assertStringContainsString('/staff/evidence/'.$evidence->id.'/preview', $url);
    }
}
