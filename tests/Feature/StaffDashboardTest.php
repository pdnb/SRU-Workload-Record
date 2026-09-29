<?php

namespace Tests\Feature;

use App\Enums\EvaluationPeriodNumber;
use App\Enums\PeriodStatus;
use App\Enums\UserRole;
use App\Enums\WorkRecordStatus;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\WorkEntry;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use App\Models\WorkRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StaffDashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, period: EvaluationPeriod, workload: Workload, group: WorkloadGroup, record: WorkRecord, fiscalYear: FiscalYear}
     */
    private function createDashboardContext(): array
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

        $record = WorkRecord::create([
            'user_id' => $user->id,
            'evaluation_period_id' => $period->id,
            'status' => WorkRecordStatus::Draft,
        ]);

        return compact('user', 'period', 'workload', 'group', 'record', 'fiscalYear');
    }

    public function test_staff_can_access_dashboard(): void
    {
        $user = User::factory()->create(['position_id' => $this->defaultPosition()->id, 'role' => UserRole::Staff]);

        $this->actingAs($user)
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('แดชบอร์ด')
            ->assertSee('สรุปภาระงานของฉัน');
    }

    public function test_hr_and_admin_cannot_access_staff_dashboard(): void
    {
        $hr = User::factory()->create(['role' => UserRole::Hr]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($hr)
            ->get(route('staff.dashboard'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('staff.dashboard'))
            ->assertForbidden();
    }

    public function test_mount_defaults_to_open_period_in_active_fiscal_year(): void
    {
        ['user' => $user, 'period' => $period, 'fiscalYear' => $fiscalYear] = $this->createDashboardContext();

        EvaluationPeriod::create([
            'fiscal_year_id' => $fiscalYear->id,
            'period' => EvaluationPeriodNumber::Two,
            'label' => 'รอบที่ 2',
            'start_date' => now()->subWeek(),
            'end_date' => now()->addWeeks(3),
            'status' => PeriodStatus::Closed,
        ]);

        Livewire::actingAs($user)
            ->test('pages::staff.dashboard')
            ->assertSet('filterFiscalYearId', $fiscalYear->id)
            ->assertSet('filterPeriodId', $period->id);
    }

    public function test_shows_summary_when_record_exists(): void
    {
        ['user' => $user, 'period' => $period, 'workload' => $workload, 'record' => $record] = $this->createDashboardContext();

        WorkEntry::create([
            'work_record_id' => $record->id,
            'workload_id' => $workload->id,
            'detail' => 'รายละเอียดทดสอบ dashboard',
            'hours' => 3,
            'sort_order' => 1,
        ]);

        Livewire::actingAs($user)
            ->test('pages::staff.dashboard')
            ->set('filterPeriodId', $period->id)
            ->assertSee('1 รายการ')
            ->assertSee('3.0')
            ->assertSee('30.00');
    }

    public function test_shows_empty_state_when_no_record(): void
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

        Livewire::actingAs($user)
            ->test('pages::staff.dashboard')
            ->set('filterPeriodId', $period->id)
            ->assertSee('ยังไม่มีบันทึกในรอบนี้')
            ->assertDontSeeHtml('id="staffDashRadarChart"');
    }

    public function test_renders_chart_section_with_canvas_elements(): void
    {
        ['user' => $user, 'period' => $period, 'workload' => $workload, 'record' => $record, 'group' => $group] = $this->createDashboardContext();

        WorkEntry::create([
            'work_record_id' => $record->id,
            'workload_id' => $workload->id,
            'detail' => 'รายละเอียดทดสอบ chart',
            'hours' => 3,
            'sort_order' => 1,
        ]);

        $component = Livewire::actingAs($user)
            ->test('pages::staff.dashboard')
            ->set('filterPeriodId', $period->id)
            ->assertSee('จำนวนงานตามกลุ่มภาระงาน')
            ->assertSee('สัดส่วนงาน')
            ->assertSee('ความคืบหน้าภาระงาน')
            ->assertSeeHtml('id="staffDashRadarChart"')
            ->assertSeeHtml('id="staffDashHoursChart"')
            ->assertSeeHtml('id="staffDashProgressChart"');

        $data = $component->get('chartData');

        $this->assertTrue($data['hasEntries']);
        $this->assertSame(['1 '.$group->name], $data['scoreByGroup']['labels']);
        $this->assertSame([30.0], $data['scoreByGroup']['earned']);
        $this->assertSame(['1 '.$group->name], $data['hoursByGroup']['labels']);
        $this->assertSame([3.0], $data['hoursByGroup']['values']);
        $this->assertSame(['1 '.$group->name], $data['hoursRadar']['labels']);
        $this->assertSame([3.0], $data['hoursRadar']['values']);
        $this->assertSame(1, $data['progress']['filled']);
    }

    public function test_chart_overlay_when_record_has_no_entries(): void
    {
        ['user' => $user, 'period' => $period, 'record' => $record] = $this->createDashboardContext();

        Livewire::actingAs($user)
            ->test('pages::staff.dashboard')
            ->set('filterPeriodId', $period->id)
            ->assertSee('0 รายการ')
            ->assertSee('ยังไม่บันทึกงาน')
            ->assertSeeHtml('id="staffDashRadarChart"');
    }

    public function test_shows_latest_entries_in_updated_at_order(): void
    {
        ['user' => $user, 'period' => $period, 'workload' => $workload, 'record' => $record] = $this->createDashboardContext();

        WorkEntry::create([
            'work_record_id' => $record->id,
            'workload_id' => $workload->id,
            'detail' => 'ผลงานเก่าสุด',
            'hours' => 1,
            'sort_order' => 1,
        ])->updateQuietly(['updated_at' => now()->subDays(3)]);

        WorkEntry::create([
            'work_record_id' => $record->id,
            'workload_id' => $workload->id,
            'detail' => 'ผลงานกลาง',
            'hours' => 2,
            'sort_order' => 2,
        ])->updateQuietly(['updated_at' => now()->subDay()]);

        WorkEntry::create([
            'work_record_id' => $record->id,
            'workload_id' => $workload->id,
            'detail' => 'ผลงานล่าสุด',
            'hours' => 3,
            'sort_order' => 3,
        ])->updateQuietly(['updated_at' => now()]);

        $html = Livewire::actingAs($user)
            ->test('pages::staff.dashboard')
            ->set('filterPeriodId', $period->id)
            ->assertSee('ผลงานล่าสุด')
            ->assertSee('ผลงานกลาง')
            ->assertSee('ผลงานเก่าสุด')
            ->html();

        $this->assertLessThan(
            strpos($html, 'ผลงานกลาง'),
            strpos($html, 'ผลงานล่าสุด'),
        );
        $this->assertLessThan(
            strpos($html, 'ผลงานเก่าสุด'),
            strpos($html, 'ผลงานกลาง'),
        );
    }

    public function test_limits_latest_entries_to_ten(): void
    {
        ['user' => $user, 'period' => $period, 'workload' => $workload, 'record' => $record] = $this->createDashboardContext();

        for ($i = 1; $i <= 12; $i++) {
            WorkEntry::create([
                'work_record_id' => $record->id,
                'workload_id' => $workload->id,
                'detail' => "ผลงานหมายเลข {$i}",
                'hours' => 1,
                'sort_order' => $i,
            ])->updateQuietly(['updated_at' => now()->subMinutes(12 - $i)]);
        }

        $component = Livewire::actingAs($user)
            ->test('pages::staff.dashboard')
            ->set('filterPeriodId', $period->id)
            ->assertSee('ผลงานหมายเลข 12')
            ->assertSee('ผลงานหมายเลข 3');

        $entries = $component->get('latestEntries');

        $this->assertCount(10, $entries);
        $this->assertSame('ผลงานหมายเลข 12', $entries->first()->detail);
        $this->assertSame('ผลงานหมายเลข 3', $entries->last()->detail);
        $this->assertFalse($entries->contains(fn (WorkEntry $entry) => $entry->detail === 'ผลงานหมายเลข 2'));
    }
}
