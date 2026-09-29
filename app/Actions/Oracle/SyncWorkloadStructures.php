<?php

namespace App\Actions\Oracle;

use App\Contracts\WorkloadSourceReader;
use App\Data\Oracle\StructureItemRow;
use App\Models\FiscalYear;
use App\Models\Position;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use App\Models\WorkloadStructure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncWorkloadStructures
{
    public function __construct(
        private WorkloadSourceReader $reader,
    ) {}

    /**
     * Upserts each (fiscal year, position) structure and its group/main/workload tree
     * by external id. Items missing from the source are deactivated, never deleted,
     * so existing work entries keep their workload and score.
     *
     * Structures without an external id (created by hand or by PMS import) are left
     * untouched unless they are still empty.
     *
     * @param  int|null  $fiscalYear  Buddhist-era year; null = every year in the source
     * @return array<string, int|list<string>>
     */
    public function __invoke(?int $fiscalYear = null): array
    {
        $stats = [
            'structures_created' => 0,
            'structures_updated' => 0,
            'structures_deactivated' => 0,
            'items_created' => 0,
            'items_updated' => 0,
            'items_deactivated' => 0,
            'skipped' => [],
        ];

        $now = now();
        $trees = $this->buildTrees($this->reader->structureItems($fiscalYear));
        $syncedStructureIds = [];

        $fiscalYears = FiscalYear::whereIn('year', array_unique(array_column($trees, 'year')))->pluck('id', 'year');
        $positions = Position::whereIn('code', array_unique(array_column($trees, 'position')))->pluck('id', 'code');

        foreach ($trees as $key => $tree) {
            $fiscalYearId = $fiscalYears[$tree['year']] ?? null;
            $positionId = $positions[$tree['position']] ?? null;

            if ($fiscalYearId === null) {
                $stats['skipped'][] = "{$key}: ไม่พบปีงบประมาณ {$tree['year']} ในระบบ";

                continue;
            }

            if ($positionId === null) {
                $stats['skipped'][] = "{$key}: ไม่พบตำแหน่ง {$tree['position']} (ซิงก์ตำแหน่งก่อน)";

                continue;
            }

            $structure = WorkloadStructure::firstOrNew([
                'fiscal_year_id' => $fiscalYearId,
                'position_id' => $positionId,
            ]);

            if ($structure->exists && $structure->external_id === null && $structure->workloadGroups()->exists()) {
                $stats['skipped'][] = "{$key}: มีโครงสร้างที่สร้างเองอยู่แล้ว (ไม่เขียนทับ)";

                continue;
            }

            // One savepoint per structure: a bad position is reported and skipped
            // without rolling back the others.
            $itemStats = ['items_created' => 0, 'items_updated' => 0, 'items_deactivated' => 0];
            $isNew = ! $structure->exists;

            try {
                DB::transaction(function () use ($structure, $key, $tree, $now, &$itemStats) {
                    $structure->fill(['external_id' => $key, 'is_active' => true, 'synced_at' => $now])->save();

                    $this->syncTree($structure, $tree['groups'], $now, $itemStats);
                });
            } catch (\Throwable $e) {
                $stats['skipped'][] = "{$key}: {$e->getMessage()}";

                continue;
            }

            $stats[$isNew ? 'structures_created' : 'structures_updated']++;

            foreach ($itemStats as $name => $count) {
                $stats[$name] += $count;
            }

            $syncedStructureIds[] = $structure->id;
        }

        // Oracle structures for the synced years that disappeared from the source.
        $syncedYearIds = $fiscalYear !== null
            ? FiscalYear::where('year', $fiscalYear)->pluck('id')
            : $fiscalYears->values();

        if ($trees !== []) {
            $stats['structures_deactivated'] = WorkloadStructure::query()
                ->whereNotNull('external_id')
                ->whereIn('fiscal_year_id', $syncedYearIds)
                ->whereNotIn('id', $syncedStructureIds)
                ->where('is_active', true)
                ->update(['is_active' => false, 'synced_at' => $now]);
        }

        return $stats;
    }

    /**
     * @param  iterable<StructureItemRow>  $rows
     * @return array<string, array{year: int, position: string, groups: array}>
     */
    protected function buildTrees(iterable $rows): array
    {
        $trees = [];

        foreach ($rows as $row) {
            $key = "{$row->fiscalYear}:{$row->positionCode}";

            $trees[$key] ??= ['year' => $row->fiscalYear, 'position' => $row->positionCode, 'groups' => []];
            $groups = &$trees[$key]['groups'];

            $groups[$row->groupId] ??= [
                'name' => $row->groupName,
                'max_score' => $row->groupMaxScore,
                'order' => $row->groupOrder ?? count($groups) + 1,
                'mains' => [],
            ];
            $mains = &$groups[$row->groupId]['mains'];

            $mains[$row->mainId] ??= [
                'name' => $row->mainName,
                'max_score' => $row->mainMaxScore,
                'order' => $row->mainOrder ?? count($mains) + 1,
                'workloads' => [],
            ];
            $workloads = &$mains[$row->mainId]['workloads'];

            $workloads[$row->workloadId] = [
                'name' => $row->workloadName,
                'score' => $row->workloadScore,
                'order' => $row->workloadOrder ?? count($workloads) + 1,
            ];

            unset($groups, $mains, $workloads);
        }

        return $trees;
    }

    protected function syncTree(WorkloadStructure $structure, array $groups, Carbon $now, array &$stats): void
    {
        $seenGroupIds = [];

        foreach ($groups as $groupExternalId => $groupData) {
            $group = $this->upsert(
                WorkloadGroup::class,
                ['workload_structure_id' => $structure->id, 'external_id' => (string) $groupExternalId],
                ['name' => $groupData['name'], 'max_score' => $groupData['max_score'], 'sort_order' => $groupData['order']],
                $now,
                $stats,
            );
            $seenGroupIds[] = $group->id;
            $seenMainIds = [];

            foreach ($groupData['mains'] as $mainExternalId => $mainData) {
                $main = $this->upsert(
                    WorkloadMain::class,
                    ['workload_group_id' => $group->id, 'external_id' => (string) $mainExternalId],
                    ['name' => $mainData['name'], 'max_score' => $mainData['max_score'], 'sort_order' => $mainData['order']],
                    $now,
                    $stats,
                );
                $seenMainIds[] = $main->id;
                $seenWorkloadIds = [];

                foreach ($mainData['workloads'] as $workloadExternalId => $workloadData) {
                    $seenWorkloadIds[] = $this->upsert(
                        Workload::class,
                        ['workload_main_id' => $main->id, 'external_id' => (string) $workloadExternalId],
                        ['name' => $workloadData['name'], 'score' => $workloadData['score'], 'sort_order' => $workloadData['order']],
                        $now,
                        $stats,
                    )->id;
                }

                $stats['items_deactivated'] += $this->deactivateMissing(
                    Workload::where('workload_main_id', $main->id), $seenWorkloadIds, $now,
                );
            }

            $stats['items_deactivated'] += $this->deactivateMissing(
                WorkloadMain::where('workload_group_id', $group->id), $seenMainIds, $now,
            );
        }

        $stats['items_deactivated'] += $this->deactivateMissing(
            WorkloadGroup::where('workload_structure_id', $structure->id), $seenGroupIds, $now,
        );
    }

    /**
     * @param  class-string<WorkloadGroup|WorkloadMain|Workload>  $model
     */
    protected function upsert(string $model, array $keys, array $values, Carbon $now, array &$stats): WorkloadGroup|WorkloadMain|Workload
    {
        $item = $model::firstOrNew($keys);
        $item->fill([...$values, 'is_active' => true]);

        if (! $item->exists) {
            $stats['items_created']++;
        } elseif ($item->isDirty()) {
            $stats['items_updated']++;
        }

        $item->synced_at = $now;
        $item->save();

        return $item;
    }

    protected function deactivateMissing($query, array $seenIds, Carbon $now): int
    {
        return $query
            ->whereNotIn('id', $seenIds)
            ->where('is_active', true)
            ->update(['is_active' => false, 'synced_at' => $now]);
    }
}
