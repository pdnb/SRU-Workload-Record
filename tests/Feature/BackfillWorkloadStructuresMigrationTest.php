<?php

namespace Tests\Feature;

use App\Models\WorkRecord;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Exercises the multi-position backfill against data shaped like production
 * before the change: groups keyed by fiscal_year_id, users without positions.
 */
class BackfillWorkloadStructuresMigrationTest extends TestCase
{
    use DatabaseMigrations;

    /** Migrations 000006 (backfill), 000007 (finalize), 000008 (oracle columns) and impersonation_logs. */
    private const STEPS_AFTER_BACKFILL = 4;

    private function rollbackToBeforeBackfill(): void
    {
        Artisan::call('migrate:rollback', ['--step' => self::STEPS_AFTER_BACKFILL]);

        $this->assertTrue(Schema::hasColumn('workload_groups', 'fiscal_year_id'));
    }

    /**
     * @return array{fy1: int, fy2: int, staff: int, admin: int, legacy: int, records: list<int>, groups: list<int>}
     */
    private function seedLegacyData(): array
    {
        $now = now();
        $fy1 = DB::table('fiscal_years')->insertGetId(['year' => 2568, 'is_active' => false, 'created_at' => $now, 'updated_at' => $now]);
        $fy2 = DB::table('fiscal_years')->insertGetId(['year' => 2569, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('fiscal_years')->insert(['year' => 2570, 'is_active' => false, 'created_at' => $now, 'updated_at' => $now]);

        $groups = [];
        $workloads = [];

        foreach ([$fy1 => 30, $fy2 => 40] as $fyId => $max) {
            $groupId = DB::table('workload_groups')->insertGetId([
                'fiscal_year_id' => $fyId, 'name' => "กลุ่ม {$fyId}", 'max_score' => $max, 'sort_order' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $mainId = DB::table('workload_mains')->insertGetId([
                'workload_group_id' => $groupId, 'name' => 'หมวด', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $workloads[$fyId] = DB::table('workloads')->insertGetId([
                'workload_main_id' => $mainId, 'name' => 'งาน', 'score' => 2.5, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $groups[] = $groupId;
        }

        $user = fn (string $email, string $role, ?string $position = null) => DB::table('users')->insertGetId([
            'name' => $email, 'email' => $email, 'role' => $role, 'position' => $position, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $staff = $user('staff@sru.ac.th', 'staff');
        $admin = $user('admin@sru.ac.th', 'admin');
        $legacy = $user('legacy@sru.ac.th', 'staff', 'นักวิชาการคอมพิวเตอร์-ปฏิบัติการ');

        $records = [];

        foreach ([$fy1, $fy2] as $fyId) {
            $periodId = DB::table('evaluation_periods')->insertGetId([
                'fiscal_year_id' => $fyId, 'period' => 1, 'label' => "รอบ {$fyId}", 'start_date' => $now, 'end_date' => $now,
                'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $recordId = DB::table('work_records')->insertGetId([
                'user_id' => $staff, 'evaluation_period_id' => $periodId, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('work_entries')->insert([
                'work_record_id' => $recordId, 'workload_id' => $workloads[$fyId], 'detail' => 'งาน', 'hours' => 4, 'sort_order' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $records[] = $recordId;
        }

        return compact('fy1', 'fy2', 'staff', 'admin', 'legacy', 'records', 'groups');
    }

    public function test_backfill_moves_existing_structure_onto_position_0901(): void
    {
        $this->rollbackToBeforeBackfill();
        $data = $this->seedLegacyData();

        Artisan::call('migrate');

        $positionId = DB::table('positions')->where('code', '0901')->value('id');
        $this->assertNotNull($positionId);

        // One structure per fiscal year in use; 2570 has nothing so gets none.
        $structures = DB::table('workload_structures')->pluck('fiscal_year_id', 'id');
        $this->assertEqualsCanonicalizing([$data['fy1'], $data['fy2']], $structures->values()->all());
        $this->assertSame(0, DB::table('workload_groups')->whereNull('workload_structure_id')->count());

        foreach ($data['groups'] as $groupId) {
            $group = DB::table('workload_groups')->find($groupId);
            $this->assertSame($positionId, DB::table('workload_structures')->where('id', $group->workload_structure_id)->value('position_id'));
        }

        // Staff get 0901 (legacy string matched by name), admin stays null.
        $this->assertSame($positionId, DB::table('users')->where('id', $data['staff'])->value('position_id'));
        $this->assertSame($positionId, DB::table('users')->where('id', $data['legacy'])->value('position_id'));
        $this->assertNull(DB::table('users')->where('id', $data['admin'])->value('position_id'));

        // Each record snapshots the structure of its own period's fiscal year, and scores are unchanged.
        foreach ($data['records'] as $index => $recordId) {
            $record = WorkRecord::find($recordId);
            $fiscalYearId = [$data['fy1'], $data['fy2']][$index];

            $this->assertSame($fiscalYearId, $record->workloadStructure->fiscal_year_id);
            $this->assertSame(10.0, $record->scoreSummary()->earned);
            $this->assertSame([30.0, 40.0][$index], $record->scoreSummary()->maximum);
        }
    }

    public function test_backfill_is_idempotent_and_reversible(): void
    {
        $this->rollbackToBeforeBackfill();
        $data = $this->seedLegacyData();

        Artisan::call('migrate');
        Artisan::call('migrate:rollback', ['--step' => self::STEPS_AFTER_BACKFILL]);

        // down() restored the legacy shape: fiscal_year_id filled back in, no positions left.
        $this->assertSame(0, DB::table('workload_groups')->whereNull('fiscal_year_id')->count());
        $this->assertSame(
            [$data['fy1'], $data['fy2']],
            DB::table('workload_groups')->whereIn('id', $data['groups'])->orderBy('id')->pluck('fiscal_year_id')->all(),
        );
        $this->assertSame(0, DB::table('positions')->count());
        $this->assertSame(0, DB::table('workload_structures')->count());

        // Running up again yields the same result, without duplicates.
        Artisan::call('migrate');

        $this->assertSame(1, DB::table('positions')->count());
        $this->assertSame(2, DB::table('workload_structures')->count());
        $this->assertSame(0, DB::table('work_records')->whereNull('workload_structure_id')->count());
    }
}
