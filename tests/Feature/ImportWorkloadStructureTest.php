<?php

namespace Tests\Feature;

use App\Actions\ImportWorkloadStructureFromPmsHtml;
use App\Enums\EvaluationPeriodNumber;
use App\Enums\PeriodStatus;
use App\Enums\UserRole;
use App\Enums\WorkRecordStatus;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\WorkEntry;
use App\Models\WorkRecord;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use App\Services\PmsWorkloadHtmlParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class ImportWorkloadStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function pmsHtml(): string
    {
        return file_get_contents(database_path('seeders/sources/pms.html'));
    }

    public function test_parser_reads_pms_html_with_expected_counts(): void
    {
        $parser = new PmsWorkloadHtmlParser;
        $structure = $parser->parse($this->pmsHtml());
        $stats = $parser->stats($structure);

        $this->assertSame(10, $stats['groups']);
        $this->assertSame(27, $stats['mains']);
        $this->assertSame(151, $stats['workloads']);
    }

    public function test_admin_can_import_structure_into_empty_fiscal_year(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $fiscalYear = FiscalYear::create(['year' => 2569, 'is_active' => true]);

        $file = UploadedFile::fake()->createWithContent('pms.html', $this->pmsHtml(), 'text/html');

        Livewire::actingAs($admin)
            ->test('pages::admin.workload-manager')
            ->set('selectedFiscalYearId', $fiscalYear->id)
            ->call('openImportModal')
            ->set('pmsHtmlFile', $file)
            ->assertSet('importPreview.groups', 10)
            ->assertSet('importPreview.mains', 27)
            ->assertSet('importPreview.workloads', 151)
            ->call('importFromPmsHtml')
            ->assertHasNoErrors();

        $this->assertSame(10, WorkloadGroup::where('fiscal_year_id', $fiscalYear->id)->count());
        $this->assertSame(27, WorkloadMain::whereHas('workloadGroup', fn ($q) => $q->where('fiscal_year_id', $fiscalYear->id))->count());
        $this->assertSame(151, Workload::whereHas('workloadMain.workloadGroup', fn ($q) => $q->where('fiscal_year_id', $fiscalYear->id))->count());
    }

    public function test_import_fails_when_fiscal_year_already_has_structure_without_replace(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $fiscalYear = FiscalYear::create(['year' => 2569, 'is_active' => true]);

        WorkloadGroup::create([
            'fiscal_year_id' => $fiscalYear->id,
            'name' => 'กลุ่มเดิม',
            'sort_order' => 1,
        ]);

        $file = UploadedFile::fake()->createWithContent('pms.html', $this->pmsHtml(), 'text/html');

        Livewire::actingAs($admin)
            ->test('pages::admin.workload-manager')
            ->set('selectedFiscalYearId', $fiscalYear->id)
            ->set('pmsHtmlFile', $file)
            ->set('replaceExistingOnImport', false)
            ->call('importFromPmsHtml')
            ->assertHasNoErrors();

        $this->assertSame(1, WorkloadGroup::where('fiscal_year_id', $fiscalYear->id)->count());
        $this->assertSame('กลุ่มเดิม', WorkloadGroup::where('fiscal_year_id', $fiscalYear->id)->value('name'));
    }

    public function test_import_replaces_existing_structure(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $fiscalYear = FiscalYear::create(['year' => 2569, 'is_active' => true]);

        $group = WorkloadGroup::create([
            'fiscal_year_id' => $fiscalYear->id,
            'name' => 'กลุ่มเดิม',
            'sort_order' => 1,
        ]);

        $main = WorkloadMain::create([
            'workload_group_id' => $group->id,
            'name' => 'หมวดเดิม',
            'sort_order' => 1,
        ]);

        $workload = Workload::create([
            'workload_main_id' => $main->id,
            'name' => 'ภาระงานเดิม',
            'sort_order' => 1,
        ]);

        $user = User::factory()->create(['role' => UserRole::Staff]);

        $period = EvaluationPeriod::create([
            'fiscal_year_id' => $fiscalYear->id,
            'period' => EvaluationPeriodNumber::One,
            'label' => 'รอบที่ 1',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'status' => PeriodStatus::Open,
        ]);

        $record = WorkRecord::create([
            'user_id' => $user->id,
            'evaluation_period_id' => $period->id,
            'status' => WorkRecordStatus::Draft,
        ]);

        WorkEntry::create([
            'work_record_id' => $record->id,
            'workload_id' => $workload->id,
            'detail' => 'รายละเอียด',
            'hours' => 1,
        ]);

        $file = UploadedFile::fake()->createWithContent('pms.html', $this->pmsHtml(), 'text/html');

        Livewire::actingAs($admin)
            ->test('pages::admin.workload-manager')
            ->set('selectedFiscalYearId', $fiscalYear->id)
            ->set('pmsHtmlFile', $file)
            ->set('replaceExistingOnImport', true)
            ->call('importFromPmsHtml')
            ->assertHasNoErrors();

        $this->assertSame(10, WorkloadGroup::where('fiscal_year_id', $fiscalYear->id)->count());
        $this->assertDatabaseMissing('workloads', ['id' => $workload->id]);
        $this->assertDatabaseMissing('work_entries', ['workload_id' => $workload->id]);
        $this->assertNotSame('กลุ่มเดิม', WorkloadGroup::where('fiscal_year_id', $fiscalYear->id)->value('name'));
    }

    public function test_non_admin_cannot_access_workload_manager(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        $this->actingAs($staff)
            ->get('/admin/workloads')
            ->assertForbidden();
    }

    public function test_import_action_throws_when_structure_exists_without_replace(): void
    {
        $fiscalYear = FiscalYear::create(['year' => 2569, 'is_active' => true]);

        WorkloadGroup::create([
            'fiscal_year_id' => $fiscalYear->id,
            'name' => 'กลุ่มเดิม',
            'sort_order' => 1,
        ]);

        $parser = new PmsWorkloadHtmlParser;
        $structure = $parser->parse($this->pmsHtml());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fiscal_year_has_structure');

        app(ImportWorkloadStructureFromPmsHtml::class)($fiscalYear, $structure, false);
    }
}
