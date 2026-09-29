<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\FiscalYear;
use App\Models\Position;
use App\Models\User;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkloadManagerPositionListTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_page_lists_positions_with_structure_counts_first(): void
    {
        $fiscalYear = FiscalYear::create(['year' => 2569, 'is_active' => true]);
        $structure = $this->structureFor($fiscalYear);
        $group = WorkloadGroup::create(['workload_structure_id' => $structure->id, 'name' => 'กลุ่มงานหลัก', 'sort_order' => 1]);
        $main = WorkloadMain::create(['workload_group_id' => $group->id, 'name' => 'หมวด', 'sort_order' => 1]);
        Workload::create(['workload_main_id' => $main->id, 'name' => 'งาน 1', 'sort_order' => 1]);
        Workload::create(['workload_main_id' => $main->id, 'name' => 'งาน 2', 'sort_order' => 2]);
        $empty = Position::factory()->create(['name' => 'ตำแหน่งว่าง']);

        $component = Livewire::actingAs($this->admin())
            ->test('pages::admin.workload-manager')
            ->assertSet('selectedPositionId', null)
            ->assertSee($this->defaultPosition()->name)
            ->assertSee('ตำแหน่งว่าง')
            ->assertSee('ยังไม่มีโครงสร้าง')
            ->assertDontSee('กลุ่มงานหลัก');

        $rows = $component->instance()->positionSummaries->keyBy(fn ($row) => $row['position']->id);
        $this->assertSame([1, 1, 2], [
            $rows[$this->defaultPosition()->id]['groups'],
            $rows[$this->defaultPosition()->id]['mains'],
            $rows[$this->defaultPosition()->id]['workloads'],
        ]);
        $this->assertSame(0, $rows[$empty->id]['workloads']);
    }

    public function test_search_filters_positions_by_code_or_name(): void
    {
        FiscalYear::create(['year' => 2569, 'is_active' => true]);
        $this->defaultPosition();
        Position::factory()->create(['code' => '1234', 'name' => 'นักวิเคราะห์นโยบาย']);

        $component = Livewire::actingAs($this->admin())->test('pages::admin.workload-manager');
        $listedCodes = fn () => $component->instance()->positionSummaries->map(fn ($row) => $row['position']->code)->all();

        $component->set('positionSearch', 'วิเคราะห์');
        $this->assertSame(['1234'], $listedCodes());

        $component->set('positionSearch', '0901');
        $this->assertSame(['0901'], $listedCodes());

        $component->set('positionSearch', 'ไม่มีตำแหน่งนี้')
            ->assertSee('ไม่พบตำแหน่งที่ค้นหา');
    }

    public function test_selecting_a_position_shows_its_structure_and_back_returns_to_list(): void
    {
        $fiscalYear = FiscalYear::create(['year' => 2569, 'is_active' => true]);
        $structure = $this->structureFor($fiscalYear);
        WorkloadGroup::create(['workload_structure_id' => $structure->id, 'name' => 'กลุ่มงานหลัก', 'sort_order' => 1]);
        $position = $this->defaultPosition();

        Livewire::actingAs($this->admin())
            ->test('pages::admin.workload-manager')
            ->call('selectPosition', $position->id)
            ->assertSet('selectedPositionId', $position->id)
            ->assertSee('กลุ่มงานหลัก')
            ->assertSee('กลับไปรายการตำแหน่ง')
            ->call('backToList')
            ->assertSet('selectedPositionId', null)
            ->assertDontSee('กลุ่มงานหลัก');
    }
}
