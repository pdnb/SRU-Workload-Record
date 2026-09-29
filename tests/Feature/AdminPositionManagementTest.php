<?php

namespace Tests\Feature;

use App\Actions\CopyStructureToPosition;
use App\Enums\PositionSource;
use App\Enums\UserRole;
use App\Models\FiscalYear;
use App\Models\Position;
use App\Models\User;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPositionManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_user_manager_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Staff]))
            ->get(route('admin.users'))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee('ผู้ใช้และตำแหน่ง');
    }

    public function test_admin_assigns_position_as_manual_override(): void
    {
        $position = $this->defaultPosition();
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        Livewire::actingAs($this->admin())
            ->test('pages::admin.user-manager')
            ->call('setUserPosition', $staff->id, (string) $position->id);

        $staff->refresh();
        $this->assertSame($position->id, $staff->position_id);
        $this->assertSame(PositionSource::Manual, $staff->position_source);
    }

    public function test_use_oracle_position_clears_the_manual_override(): void
    {
        $staff = User::factory()->create([
            'role' => UserRole::Staff,
            'position_id' => $this->defaultPosition()->id,
            'position_source' => PositionSource::Manual,
        ]);

        Livewire::actingAs($this->admin())
            ->test('pages::admin.user-manager')
            ->call('useOraclePosition', $staff->id);

        $this->assertSame(PositionSource::Oracle, $staff->fresh()->position_source);
    }

    public function test_admin_manages_local_positions_but_not_oracle_ones(): void
    {
        $component = Livewire::actingAs($this->admin())
            ->test('pages::admin.user-manager')
            ->call('openCreatePosition')
            ->set('positionCode', '0501')
            ->set('positionName', 'นักวิชาการเงินและบัญชี')
            ->call('savePosition')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('positions', ['code' => '0501', 'external_id' => null]);

        $component
            ->call('openCreatePosition')
            ->set('positionCode', '0501')
            ->set('positionName', 'ซ้ำ')
            ->call('savePosition')
            ->assertHasErrors('positionCode');

        $oracle = Position::factory()->fromOracle()->create();
        $component->call('openEditPosition', $oracle->id)->assertForbidden();
    }

    public function test_position_in_use_cannot_be_deleted(): void
    {
        $position = $this->defaultPosition();
        User::factory()->create(['role' => UserRole::Staff, 'position_id' => $position->id]);

        Livewire::actingAs($this->admin())
            ->test('pages::admin.user-manager')
            ->call('deletePosition', $position->id);

        $this->assertNotNull($position->fresh());
    }

    public function test_import_targets_the_selected_position(): void
    {
        $fiscalYear = FiscalYear::create(['year' => 2569, 'is_active' => true]);
        $computer = $this->defaultPosition();
        $finance = Position::factory()->create(['code' => '0501']);

        $html = file_get_contents(database_path('seeders/sources/pms.html'));

        Livewire::actingAs($this->admin())
            ->test('pages::admin.workload-manager')
            ->set('selectedFiscalYearId', $fiscalYear->id)
            ->set('selectedPositionId', $finance->id)
            ->call('openImportModal')
            ->set('pmsHtmlFile', UploadedFile::fake()->createWithContent('pms.html', $html))
            ->assertSet('importDetectedPosition.code', '0901')
            ->assertSee('ไม่ตรงกับตำแหน่งที่เลือก')
            ->call('importFromPmsHtml')
            ->assertHasNoErrors();

        $this->assertSame(10, $this->structureFor($fiscalYear, $finance)->workloadGroups()->count());
        $this->assertSame(0, $this->structureFor($fiscalYear, $computer)->workloadGroups()->count());
    }

    public function test_copy_structure_to_another_position(): void
    {
        $fiscalYear = FiscalYear::create(['year' => 2569, 'is_active' => true]);
        $source = $this->structureFor($fiscalYear);
        $target = Position::factory()->create();

        $group = WorkloadGroup::create(['workload_structure_id' => $source->id, 'name' => 'กลุ่ม', 'max_score' => 20, 'sort_order' => 1]);
        $main = WorkloadMain::create(['workload_group_id' => $group->id, 'name' => 'หมวด', 'sort_order' => 1]);
        Workload::create(['workload_main_id' => $main->id, 'name' => 'งาน', 'score' => 3, 'sort_order' => 1]);

        Livewire::actingAs($this->admin())
            ->test('pages::admin.workload-manager')
            ->set('selectedFiscalYearId', $fiscalYear->id)
            ->set('selectedPositionId', $this->defaultPosition()->id)
            ->call('openCopyPositionModal')
            ->set('copyTargetPositionId', $target->id)
            ->call('copyToPosition')
            ->assertHasNoErrors()
            ->assertSet('selectedPositionId', $target->id);

        $copied = $this->structureFor($fiscalYear, $target);
        $this->assertCount(1, $copied->workloadIds());
        $this->assertSame('20.00', $copied->workloadGroups()->first()->max_score);

        // Copying again without replace is refused.
        $this->expectExceptionMessage('target_has_structure');
        app(CopyStructureToPosition::class)($source, $target);
    }

    public function test_oracle_structure_is_read_only_in_the_manager(): void
    {
        $fiscalYear = FiscalYear::create(['year' => 2569, 'is_active' => true]);
        $position = Position::factory()->fromOracle()->create();
        $structure = $this->structureFor($fiscalYear, $position);
        $structure->update(['external_id' => "2569:{$position->code}"]);
        $group = WorkloadGroup::create(['workload_structure_id' => $structure->id, 'name' => 'จาก Oracle', 'sort_order' => 1]);

        Livewire::actingAs($this->admin())
            ->test('pages::admin.workload-manager')
            ->set('selectedFiscalYearId', $fiscalYear->id)
            ->set('selectedPositionId', $position->id)
            ->assertSet('isLocked', true)
            ->assertSee('จาก Oracle')
            ->assertDontSeeHtml('wire:click="openCreateGroup"')
            ->assertDontSeeHtml('wire:click="deleteGroup(')
            ->call('deleteGroup', $group->id)
            ->assertForbidden();

        $this->assertNotNull($group->fresh());
    }
}
