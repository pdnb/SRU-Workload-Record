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

class DeleteModalEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_modal_entry_removes_entry_from_memory_and_database(): void
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

        $workload = Workload::create([
            'workload_main_id' => $main->id,
            'name' => 'ภาระงานทดสอบ',
            'sort_order' => 1,
        ]);

        $component = Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2)
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', 'รายละเอียดการดำเนินงานทดสอบลบจาก modal')
            ->call('saveModalEntry')
            ->assertHasNoErrors()
            ->assertSet('entryModalOpen', false);

        $record = $user->workRecords()->first();
        $this->assertNotNull($record);
        $this->assertSame(1, $record->workEntries()->count());

        $component
            ->call('openEntryModal', $workload->id, 0)
            ->assertSet('modalEntryIndex', 0)
            ->assertSet('entryModalOpen', true)
            ->call('deleteModalEntry')
            ->assertHasNoErrors()
            ->assertSet('entryModalOpen', false);

        $this->assertSame([], $component->get('entries')[$workload->id] ?? []);
        $this->assertSame(0, $record->fresh()->workEntries()->count());
    }
}
