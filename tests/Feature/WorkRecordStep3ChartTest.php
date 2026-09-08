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

class WorkRecordStep3ChartTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, period: EvaluationPeriod, workload: Workload, group: WorkloadGroup}
     */
    private function createChartContext(): array
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

        return compact('user', 'period', 'workload', 'group');
    }

    public function test_step3_renders_chart_section_with_canvas_elements(): void
    {
        ['user' => $user, 'period' => $period, 'workload' => $workload] = $this->createChartContext();

        Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->call('goToStep', 2)
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', 'รายละเอียดทดสอบ chart')
            ->set('modalEntry.hours', '3')
            ->call('saveModalEntry')
            ->call('goToStep', 3)
            ->assertSee('คะแนนตามกลุ่มภาระงาน')
            ->assertSee('สัดส่วนงาน')
            ->assertSee('ความคืบหน้าภาระงาน')
            ->assertSeeHtml('id="step3ScoreChart"')
            ->assertSeeHtml('id="step3HoursChart"')
            ->assertSeeHtml('id="step3ProgressChart"');
    }

    public function test_step3_chart_data_reflects_entries(): void
    {
        ['user' => $user, 'period' => $period, 'workload' => $workload, 'group' => $group] = $this->createChartContext();

        $component = Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->call('goToStep', 2)
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', 'รายละเอียดทดสอบ chart data')
            ->set('modalEntry.hours', '3')
            ->call('saveModalEntry')
            ->call('goToStep', 3);

        $data = $component->get('step3ChartData');

        $this->assertTrue($data['hasEntries']);
        $this->assertSame(['1 '.$group->name], $data['scoreByGroup']['labels']);
        $this->assertSame([30.0], $data['scoreByGroup']['earned']);
        $this->assertSame([100.0], $data['scoreByGroup']['max']);
        $this->assertSame(['1 '.$group->name], $data['hoursByGroup']['labels']);
        $this->assertSame([3.0], $data['hoursByGroup']['values']);
        $this->assertSame(1, $data['progress']['filled']);
        $this->assertSame(0, $data['progress']['empty']);
        $this->assertSame(100, $data['progress']['percent']);
    }

    public function test_step3_chart_data_empty_when_no_entries(): void
    {
        ['user' => $user, 'period' => $period] = $this->createChartContext();

        $component = Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->call('goToStep', 3);

        $data = $component->get('step3ChartData');

        $this->assertFalse($data['hasEntries']);
        $this->assertSame([], $data['hoursByGroup']['values']);
        $this->assertSame(0, $data['progress']['filled']);

        $component
            ->assertSee('ยังไม่บันทึกงาน')
            ->assertSeeHtml('id="step3ScoreChart"');
    }
}
