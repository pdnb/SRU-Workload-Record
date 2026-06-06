<?php

namespace Tests\Feature;

use App\Actions\CloneWorkload;
use App\Actions\CopyStructureToNextFiscalYear;
use App\Enums\UserRole;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkloadScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_max_score_and_workload_score(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $fiscalYear = FiscalYear::create(['year' => 2568, 'is_active' => true]);

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

        Livewire::actingAs($admin)
            ->test('pages::admin.workload-manager')
            ->set('selectedFiscalYearId', $fiscalYear->id)
            ->call('openEditGroup', $group->id)
            ->set('groupMaxScore', '100')
            ->call('saveGroup')
            ->assertHasNoErrors()
            ->call('openEditMain', $main->id)
            ->set('mainMaxScore', '50')
            ->call('saveMain')
            ->assertHasNoErrors()
            ->call('openEditWorkload', $workload->id)
            ->set('workloadScore', '12.5')
            ->call('saveWorkload')
            ->assertHasNoErrors();

        $this->assertSame('100.00', $group->fresh()->max_score);
        $this->assertSame('50.00', $main->fresh()->max_score);
        $this->assertSame('12.50', $workload->fresh()->score);
    }

    public function test_clone_workload_copies_score(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);

        $fiscalYear = FiscalYear::create(['year' => 2568, 'is_active' => true]);

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
            'score' => 25.50,
            'sort_order' => 1,
        ]);

        $clone = app(CloneWorkload::class)($workload);

        $this->assertSame('25.50', $clone->score);
    }

    public function test_copy_structure_to_next_fiscal_year_copies_scores(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);

        $source = FiscalYear::create(['year' => 2568, 'is_active' => true]);

        $group = WorkloadGroup::create([
            'fiscal_year_id' => $source->id,
            'name' => 'กลุ่มทดสอบ',
            'max_score' => 100,
            'sort_order' => 1,
        ]);

        $main = WorkloadMain::create([
            'workload_group_id' => $group->id,
            'name' => 'หมวดทดสอบ',
            'max_score' => 50,
            'sort_order' => 1,
        ]);

        Workload::create([
            'workload_main_id' => $main->id,
            'name' => 'ภาระงานทดสอบ',
            'score' => 12.5,
            'sort_order' => 1,
        ]);

        $target = app(CopyStructureToNextFiscalYear::class)($source);

        $targetGroup = WorkloadGroup::where('fiscal_year_id', $target->id)->first();
        $targetMain = $targetGroup->workloadMains()->first();
        $targetWorkload = $targetMain->workloads()->first();

        $this->assertSame(2569, $target->year);
        $this->assertSame('100.00', $targetGroup->max_score);
        $this->assertSame('50.00', $targetMain->max_score);
        $this->assertSame('12.50', $targetWorkload->score);
    }
}
