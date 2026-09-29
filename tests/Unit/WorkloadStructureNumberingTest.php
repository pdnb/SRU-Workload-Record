<?php

namespace Tests\Unit;

use App\Models\FiscalYear;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use App\Support\WorkloadStructureNumbering;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkloadStructureNumberingTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_hierarchical_numbers_for_nested_structure(): void
    {
        $fiscalYear = FiscalYear::create(['year' => 2568, 'is_active' => true]);

        $group1 = WorkloadGroup::create([
            'workload_structure_id' => $this->structureFor($fiscalYear->id)->id,
            'name' => 'กลุ่ม 1',
            'sort_order' => 1,
        ]);

        $main11 = WorkloadMain::create([
            'workload_group_id' => $group1->id,
            'name' => 'หมวด 1.1',
            'sort_order' => 1,
        ]);

        $workload111 = Workload::create([
            'workload_main_id' => $main11->id,
            'name' => 'ภาระงาน 1.1.1',
            'sort_order' => 1,
        ]);

        $workload112 = Workload::create([
            'workload_main_id' => $main11->id,
            'name' => 'ภาระงาน 1.1.2',
            'sort_order' => 2,
        ]);

        $main12 = WorkloadMain::create([
            'workload_group_id' => $group1->id,
            'name' => 'หมวด 1.2',
            'sort_order' => 2,
        ]);

        $workload121 = Workload::create([
            'workload_main_id' => $main12->id,
            'name' => 'ภาระงาน 1.2.1',
            'sort_order' => 1,
        ]);

        $group2 = WorkloadGroup::create([
            'workload_structure_id' => $this->structureFor($fiscalYear->id)->id,
            'name' => 'กลุ่ม 2',
            'sort_order' => 2,
        ]);

        $main21 = WorkloadMain::create([
            'workload_group_id' => $group2->id,
            'name' => 'หมวด 2.1',
            'sort_order' => 1,
        ]);

        $workload211 = Workload::create([
            'workload_main_id' => $main21->id,
            'name' => 'ภาระงาน 2.1.1',
            'sort_order' => 1,
        ]);

        $groups = WorkloadGroup::query()
            ->forStructure($this->structureFor($fiscalYear)->id)
            ->with(['workloadMains.workloads'])
            ->get();

        $map = WorkloadStructureNumbering::buildMap($groups);

        $this->assertSame('1', $map['groups'][$group1->id]);
        $this->assertSame('2', $map['groups'][$group2->id]);
        $this->assertSame('1.1', $map['mains'][$main11->id]);
        $this->assertSame('1.2', $map['mains'][$main12->id]);
        $this->assertSame('2.1', $map['mains'][$main21->id]);
        $this->assertSame('1.1.1', $map['workloads'][$workload111->id]);
        $this->assertSame('1.1.2', $map['workloads'][$workload112->id]);
        $this->assertSame('1.2.1', $map['workloads'][$workload121->id]);
        $this->assertSame('2.1.1', $map['workloads'][$workload211->id]);
    }

    public function test_uses_one_based_position_not_raw_sort_order_values(): void
    {
        $fiscalYear = FiscalYear::create(['year' => 2569, 'is_active' => true]);

        $group = WorkloadGroup::create([
            'workload_structure_id' => $this->structureFor($fiscalYear->id)->id,
            'name' => 'กลุ่ม',
            'sort_order' => 10,
        ]);

        $main = WorkloadMain::create([
            'workload_group_id' => $group->id,
            'name' => 'หมวด',
            'sort_order' => 0,
        ]);

        $workload = Workload::create([
            'workload_main_id' => $main->id,
            'name' => 'ภาระงาน',
            'sort_order' => 99,
        ]);

        $groups = WorkloadGroup::query()
            ->forStructure($this->structureFor($fiscalYear)->id)
            ->with(['workloadMains.workloads'])
            ->get();

        $map = WorkloadStructureNumbering::buildMap($groups);

        $this->assertSame('1', $map['groups'][$group->id]);
        $this->assertSame('1.1', $map['mains'][$main->id]);
        $this->assertSame('1.1.1', $map['workloads'][$workload->id]);
    }
}
