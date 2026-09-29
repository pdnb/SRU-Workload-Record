<?php

namespace Database\Seeders;

use App\Models\FiscalYear;
use App\Models\Position;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use App\Models\WorkloadStructure;
use Illuminate\Database\Seeder;

class WorkloadStructureSeeder extends Seeder
{
    public function run(): void
    {
        $fy = FiscalYear::where('year', 2569)->firstOrFail();
        $position = Position::where('code', PositionSeeder::COMPUTER_OFFICER_CODE)->firstOrFail();

        $workloadStructure = WorkloadStructure::firstOrCreate([
            'fiscal_year_id' => $fy->id,
            'position_id' => $position->id,
        ]);

        /** @var list<array{name: string, max_score: float, mains: array<string, list<array{name: string, score: float}>>}> $structure */
        $structure = require database_path('seeders/data/pms_computer_officer_2569.php');

        foreach ($structure as $gIndex => $groupData) {
            $group = WorkloadGroup::create([
                'workload_structure_id' => $workloadStructure->id,
                'name' => $groupData['name'],
                'max_score' => $groupData['max_score'],
                'sort_order' => $gIndex + 1,
            ]);

            $mIndex = 0;
            foreach ($groupData['mains'] as $mainName => $workloads) {
                $mIndex++;
                $main = WorkloadMain::create([
                    'workload_group_id' => $group->id,
                    'name' => $mainName,
                    'sort_order' => $mIndex,
                ]);

                foreach ($workloads as $wIndex => $workload) {
                    Workload::create([
                        'workload_main_id' => $main->id,
                        'name' => $workload['name'],
                        'score' => $workload['score'],
                        'sort_order' => $wIndex + 1,
                    ]);
                }
            }
        }
    }
}
