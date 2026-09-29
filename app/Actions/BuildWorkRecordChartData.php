<?php

namespace App\Actions;

use App\Models\WorkEntry;
use App\Models\WorkloadGroup;
use App\Models\WorkRecord;
use App\Support\WorkloadStructureNumbering;

class BuildWorkRecordChartData
{
    public function __construct(
        private CalculateWorkRecordScore $scoreCalculator,
    ) {}

    public function __invoke(WorkRecord $record): array
    {
        $record->loadMissing([
            'workEntries.workload.workloadMain.workloadGroup',
            'evaluationPeriod',
        ]);

        $groups = WorkloadGroup::query()
            ->forStructure($record->resolvedStructureId())
            ->withActiveTree()
            ->get();

        $numbers = WorkloadStructureNumbering::buildMap($groups);
        $entriesByWorkload = $record->workEntries->groupBy('workload_id');

        $flatWorkloads = collect();

        foreach ($groups as $group) {
            foreach ($group->workloadMains as $main) {
                foreach ($main->workloads as $workload) {
                    $workloadEntries = $entriesByWorkload->get($workload->id, collect());

                    $flatWorkloads->push([
                        'group_id' => $group->id,
                        'group_name' => $group->name,
                        'group_number' => $numbers['groups'][$group->id] ?? '',
                        'group_max_score' => $group->max_score,
                        'main_id' => $main->id,
                        'main_max_score' => $main->max_score,
                        'entry_count' => $workloadEntries->count(),
                        'hours_sum' => (float) $workloadEntries->sum('hours'),
                        'earned_score' => (float) $workloadEntries->sum(
                            fn (WorkEntry $entry) => $this->scoreCalculator->entryScore($entry),
                        ),
                    ]);
                }
            }
        }

        $mainTotals = $flatWorkloads
            ->groupBy('main_id')
            ->map(function ($items) {
                $total = (float) $items->sum('earned_score');
                $max = $items->first()['main_max_score'] ?? null;

                return $max !== null ? min($total, (float) $max) : $total;
            });

        $scoreByGroup = [];
        $hoursByGroup = [];

        foreach ($flatWorkloads->groupBy('group_id') as $items) {
            $first = $items->first();
            $label = trim(($first['group_number'] ?? '').' '.($first['group_name'] ?? ''));

            $groupTotal = (float) $items
                ->pluck('main_id')
                ->unique()
                ->sum(fn (int $mainId) => $mainTotals[$mainId] ?? 0.0);

            $max = $first['group_max_score'] ?? null;

            if ($max !== null) {
                $groupTotal = min($groupTotal, (float) $max);
            }

            $scoreByGroup[] = [
                'label' => $label,
                'earned' => $groupTotal,
                'max' => $max !== null ? (float) $max : null,
            ];

            $hours = (float) $items->sum('hours_sum');

            if ($hours > 0) {
                $hoursByGroup[] = [
                    'label' => $label,
                    'hours' => $hours,
                ];
            }
        }

        $total = $flatWorkloads->count();
        $filled = $flatWorkloads->where('entry_count', '>', 0)->count();
        $percent = $total > 0 ? (int) round(($filled / $total) * 100) : 0;

        return [
            'hasEntries' => $record->workEntries->isNotEmpty(),
            'scoreByGroup' => [
                'labels' => array_column($scoreByGroup, 'label'),
                'earned' => array_column($scoreByGroup, 'earned'),
                'max' => array_map(fn (array $group) => $group['max'], $scoreByGroup),
            ],
            'hoursByGroup' => [
                'labels' => array_column($hoursByGroup, 'label'),
                'values' => array_column($hoursByGroup, 'hours'),
            ],
            'progress' => [
                'filled' => $filled,
                'empty' => max(0, $total - $filled),
                'percent' => $percent,
            ],
        ];
    }

    public function empty(): array
    {
        return [
            'hasEntries' => false,
            'scoreByGroup' => ['labels' => [], 'earned' => [], 'max' => []],
            'hoursByGroup' => ['labels' => [], 'values' => []],
            'progress' => ['filled' => 0, 'empty' => 0, 'percent' => 0],
        ];
    }
}
