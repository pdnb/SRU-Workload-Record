<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moves the single per-fiscal-year structure that existed before multi-position
 * support onto position 0901, and snapshots every existing work record onto it.
 *
 * Uses the query builder only so later model changes cannot break it, and every
 * step is idempotent so re-running is safe.
 */
return new class extends Migration
{
    private const DEFAULT_POSITION_CODE = '0901';

    private const DEFAULT_POSITION_NAME = 'นักวิชาการคอมพิวเตอร์-ปฏิบัติการ';

    public function up(): void
    {
        // Fresh install: nothing to move, and no default position should appear.
        $hasLegacyData = DB::table('workload_groups')->exists()
            || DB::table('work_records')->exists()
            || DB::table('users')->where('role', 'staff')->exists();

        if (! $hasLegacyData) {
            return;
        }

        DB::transaction(function () {
            $now = now();

            // Step 1: default position
            $positionId = DB::table('positions')->where('code', self::DEFAULT_POSITION_CODE)->value('id')
                ?? DB::table('positions')->insertGetId([
                    'code' => self::DEFAULT_POSITION_CODE,
                    'name' => self::DEFAULT_POSITION_NAME,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            // Step 2: one structure per fiscal year in use (has groups or has records)
            $fiscalYearIds = DB::table('workload_groups')->distinct()->pluck('fiscal_year_id')
                ->merge(
                    DB::table('work_records')
                        ->join('evaluation_periods', 'evaluation_periods.id', '=', 'work_records.evaluation_period_id')
                        ->distinct()
                        ->pluck('evaluation_periods.fiscal_year_id'),
                )
                ->unique()
                ->values();

            $structureIds = [];

            foreach ($fiscalYearIds as $fiscalYearId) {
                $structureIds[$fiscalYearId] = DB::table('workload_structures')
                    ->where('fiscal_year_id', $fiscalYearId)
                    ->where('position_id', $positionId)
                    ->value('id')
                    ?? DB::table('workload_structures')->insertGetId([
                        'fiscal_year_id' => $fiscalYearId,
                        'position_id' => $positionId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            // Step 3: groups -> structure
            foreach ($structureIds as $fiscalYearId => $structureId) {
                DB::table('workload_groups')
                    ->where('fiscal_year_id', $fiscalYearId)
                    ->whereNull('workload_structure_id')
                    ->update(['workload_structure_id' => $structureId]);
            }

            $orphanGroups = DB::table('workload_groups')->whereNull('workload_structure_id')->count();

            if ($orphanGroups > 0) {
                throw new RuntimeException("Backfill failed: {$orphanGroups} workload_groups have no structure.");
            }

            // Step 4: staff users -> position (match legacy string first, else default)
            $positions = DB::table('positions')->get(['id', 'code', 'name']);

            DB::table('users')
                ->where('role', 'staff')
                ->whereNull('position_id')
                ->orderBy('id')
                ->get(['id', 'position'])
                ->each(function ($user) use ($positions, $positionId) {
                    $legacy = trim((string) $user->position);

                    $matched = $legacy === '' ? null : $positions->first(
                        fn ($p) => $p->code === $legacy || $p->name === $legacy,
                    );

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['position_id' => $matched->id ?? $positionId]);
                });

            // Step 5: records -> structure snapshot. Always the default structure,
            // because every existing record was entered against it.
            foreach ($structureIds as $fiscalYearId => $structureId) {
                DB::table('work_records')
                    ->whereNull('workload_structure_id')
                    ->whereIn('evaluation_period_id', DB::table('evaluation_periods')
                        ->where('fiscal_year_id', $fiscalYearId)
                        ->select('id'))
                    ->update(['workload_structure_id' => $structureId]);
            }

            $orphanRecords = DB::table('work_records')->whereNull('workload_structure_id')->count();

            if ($orphanRecords > 0) {
                throw new RuntimeException("Backfill failed: {$orphanRecords} work_records have no structure.");
            }

            // Step 6: consistency report only
            $mismatched = DB::table('work_entries')
                ->join('work_records', 'work_records.id', '=', 'work_entries.work_record_id')
                ->join('workloads', 'workloads.id', '=', 'work_entries.workload_id')
                ->join('workload_mains', 'workload_mains.id', '=', 'workloads.workload_main_id')
                ->join('workload_groups', 'workload_groups.id', '=', 'workload_mains.workload_group_id')
                ->whereColumn('workload_groups.workload_structure_id', '!=', 'work_records.workload_structure_id')
                ->count();

            Log::info('Multi-position structure backfill finished.', [
                'structures' => count($structureIds),
                'mismatched_work_entries' => $mismatched,
            ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $positionId = DB::table('positions')->where('code', self::DEFAULT_POSITION_CODE)->value('id');

            DB::table('work_records')->update(['workload_structure_id' => null]);
            DB::table('users')->update(['position_id' => null]);
            DB::table('workload_groups')->update(['workload_structure_id' => null]);

            if ($positionId === null) {
                return;
            }

            DB::table('workload_structures')->where('position_id', $positionId)->delete();
            DB::table('positions')->where('id', $positionId)->delete();
        });
    }
};
