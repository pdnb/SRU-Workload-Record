<?php

namespace Tests\Feature;

use App\Enums\EvaluationPeriodNumber;
use App\Enums\PeriodStatus;
use App\Enums\UserRole;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkRecordSidebarCategoryTotalsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, period: EvaluationPeriod, workload: Workload, group: WorkloadGroup, main: WorkloadMain}
     */
    private function createContext(?float $mainMaxScore = null): array
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
            'max_score' => $mainMaxScore,
            'sort_order' => 1,
        ]);

        $workload = Workload::create([
            'workload_main_id' => $main->id,
            'name' => 'ภาระงานทดสอบ',
            'score' => 10,
            'sort_order' => 1,
        ]);

        return compact('user', 'period', 'workload', 'group', 'main');
    }

    public function test_category_totals_sum_entries_and_scores(): void
    {
        ['user' => $user, 'period' => $period, 'workload' => $workload, 'group' => $group, 'main' => $main] = $this->createContext();

        $component = Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->call('goToStep', 2)
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', 'รายการแรก')
            ->set('modalEntry.hours', '2')
            ->call('saveModalEntry')
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', 'รายการที่สอง')
            ->set('modalEntry.hours', '1')
            ->call('saveModalEntry');

        $totals = $component->get('categoryTotals');

        $this->assertSame(2, $totals['mains'][$main->id]['entry_count']);
        $this->assertSame(30.0, $totals['mains'][$main->id]['score']);
        $this->assertNull($totals['mains'][$main->id]['max_score']);
        $this->assertSame(2, $totals['groups'][$group->id]['entry_count']);
        $this->assertSame(30.0, $totals['groups'][$group->id]['score']);
        $this->assertSame(100.0, $totals['groups'][$group->id]['max_score']);

        $component
            ->assertSee('2 ชิ้น')
            ->assertSee('30 คะแนน')
            ->assertDontSee('30/100');
    }

    public function test_category_totals_cap_score_at_main_max(): void
    {
        ['user' => $user, 'period' => $period, 'workload' => $workload, 'group' => $group, 'main' => $main] = $this->createContext(mainMaxScore: 15);

        $component = Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->call('goToStep', 2)
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', 'รายการเกินเพดาน')
            ->set('modalEntry.hours', '3')
            ->call('saveModalEntry');

        $totals = $component->get('categoryTotals');

        $this->assertSame(15.0, $totals['mains'][$main->id]['score']);
        $this->assertSame(15.0, $totals['groups'][$group->id]['score']);
    }
}
