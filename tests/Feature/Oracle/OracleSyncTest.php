<?php

namespace Tests\Feature\Oracle;

use App\Actions\Oracle\RunOracleSync;
use App\Actions\Oracle\SyncPositions;
use App\Actions\Oracle\SyncStaffPositions;
use App\Actions\Oracle\SyncWorkloadStructures;
use App\Contracts\WorkloadSourceReader;
use App\Data\Oracle\PositionRow;
use App\Data\Oracle\StaffPositionRow;
use App\Enums\EvaluationPeriodNumber;
use App\Enums\PeriodStatus;
use App\Enums\PositionSource;
use App\Enums\UserRole;
use App\Enums\WorkRecordStatus;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use App\Models\OracleSyncRun;
use App\Models\Position;
use App\Models\User;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadStructure;
use App\Models\WorkRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InMemoryWorkloadSourceReader;
use Tests\TestCase;

class OracleSyncTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryWorkloadSourceReader $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = new InMemoryWorkloadSourceReader;
        $this->app->instance(WorkloadSourceReader::class, $this->source);

        FiscalYear::create(['year' => 2569, 'is_active' => true]);
    }

    private function item(string $position, string $group, string $main, string $workload, ?float $score = 1.0, ...$rest)
    {
        return InMemoryWorkloadSourceReader::item(2569, $position, $group, $main, $workload, $score, ...$rest);
    }

    // --- positions --------------------------------------------------------

    public function test_positions_are_created_updated_and_deactivated(): void
    {
        $this->source->positions = [
            new PositionRow('0901', '0901', 'นักวิชาการคอมพิวเตอร์'),
            new PositionRow('0501', '0501', 'นักวิชาการเงิน'),
        ];

        $this->assertSame(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0], app(SyncPositions::class)());

        $this->source->positions = [new PositionRow('0901', '0901', 'นักวิชาการคอมพิวเตอร์-ปฏิบัติการ')];

        $this->assertSame(['created' => 0, 'updated' => 1, 'unchanged' => 0, 'deactivated' => 1], app(SyncPositions::class)());
        $this->assertSame('นักวิชาการคอมพิวเตอร์-ปฏิบัติการ', Position::where('code', '0901')->value('name'));
        $this->assertFalse(Position::where('code', '0501')->first()->is_active);
    }

    public function test_local_position_with_same_code_is_adopted_not_duplicated(): void
    {
        $local = $this->defaultPosition();
        $this->source->positions = [new PositionRow('P-0901', '0901', 'จาก Oracle')];

        app(SyncPositions::class)();

        $this->assertSame(1, Position::count());
        $this->assertSame('P-0901', $local->fresh()->external_id);
    }

    public function test_empty_source_never_mass_deactivates_positions(): void
    {
        Position::factory()->fromOracle()->create();

        app(SyncPositions::class)();

        $this->assertSame(1, Position::where('is_active', true)->count());
    }

    // --- structures -------------------------------------------------------

    public function test_structure_tree_is_created_per_position(): void
    {
        Position::factory()->fromOracle('0901')->create(['code' => '0901']);
        Position::factory()->fromOracle('0501')->create(['code' => '0501']);

        $this->source->structureItems = [
            $this->item('0901', 'G1', 'M1', 'W1', 2, groupMax: 40, mainMax: 20),
            $this->item('0901', 'G1', 'M1', 'W2', 3, groupMax: 40, mainMax: 20),
            $this->item('0901', 'G2', 'M2', 'W3'),
            $this->item('0501', 'G1', 'M1', 'W1'),
        ];

        $stats = app(SyncWorkloadStructures::class)();

        $this->assertSame(2, $stats['structures_created']);
        $this->assertSame(4 + 3 + 3, $stats['items_created']);

        $structure = WorkloadStructure::whereHas('position', fn ($q) => $q->where('code', '0901'))->first();
        $this->assertSame('2569:0901', $structure->external_id);
        $this->assertTrue($structure->isFromOracle());
        $this->assertSame(['กลุ่ม G1', 'กลุ่ม G2'], $structure->workloadGroups->pluck('name')->all());
        $this->assertSame('40.00', $structure->workloadGroups->first()->max_score);
        $this->assertCount(3, $structure->workloadIds());
    }

    public function test_resync_updates_in_place_and_is_idempotent(): void
    {
        Position::factory()->fromOracle('0901')->create(['code' => '0901']);
        $this->source->structureItems = [$this->item('0901', 'G1', 'M1', 'W1', 2)];

        app(SyncWorkloadStructures::class)();
        $workload = Workload::firstWhere('external_id', 'W1');

        $this->source->structureItems = [$this->item('0901', 'G1', 'M1', 'W1', 5, workloadName: 'ชื่อใหม่')];
        $stats = app(SyncWorkloadStructures::class)();

        $this->assertSame(0, $stats['items_created']);
        $this->assertSame(1, $stats['items_updated']);
        $this->assertSame('ชื่อใหม่', $workload->fresh()->name);
        $this->assertSame('5.00', $workload->fresh()->score);

        $again = app(SyncWorkloadStructures::class)();
        $this->assertSame(0, $again['items_created'] + $again['items_updated'] + $again['items_deactivated']);
        $this->assertSame(1, Workload::count());
    }

    public function test_removed_item_with_entries_is_deactivated_and_old_score_is_kept(): void
    {
        $position = Position::factory()->fromOracle('0901')->create(['code' => '0901']);
        $this->source->structureItems = [
            $this->item('0901', 'G1', 'M1', 'W1', 2),
            $this->item('0901', 'G1', 'M1', 'W2', 3),
        ];
        app(SyncWorkloadStructures::class)();

        $removed = Workload::firstWhere('external_id', 'W2');
        $structure = WorkloadStructure::first();
        $period = EvaluationPeriod::create([
            'fiscal_year_id' => $structure->fiscal_year_id, 'period' => EvaluationPeriodNumber::One, 'label' => 'รอบ 1',
            'start_date' => now(), 'end_date' => now()->addMonth(), 'status' => PeriodStatus::Open,
        ]);
        $record = WorkRecord::create([
            'user_id' => User::factory()->create(['role' => UserRole::Staff, 'position_id' => $position->id])->id,
            'evaluation_period_id' => $period->id,
            'workload_structure_id' => $structure->id,
            'status' => WorkRecordStatus::Draft,
        ]);
        $record->workEntries()->create(['workload_id' => $removed->id, 'detail' => 'งาน', 'hours' => 2, 'sort_order' => 0]);
        $scoreBefore = $record->scoreSummary()->earned;

        $this->source->structureItems = [$this->item('0901', 'G1', 'M1', 'W1', 2)];
        $stats = app(SyncWorkloadStructures::class)();

        $this->assertSame(1, $stats['items_deactivated']);
        $this->assertNotNull($removed->fresh());
        $this->assertFalse($removed->fresh()->is_active);
        $this->assertSame(1, $record->workEntries()->count());
        $this->assertSame($scoreBefore, $record->fresh()->scoreSummary()->earned);
    }

    public function test_local_structure_with_groups_is_left_alone(): void
    {
        $position = $this->defaultPosition();
        $local = $this->structureFor(FiscalYear::first(), $position);
        WorkloadGroup::create(['workload_structure_id' => $local->id, 'name' => 'กลุ่มที่สร้างเอง', 'sort_order' => 1]);

        $this->source->structureItems = [$this->item('0901', 'G1', 'M1', 'W1')];
        $stats = app(SyncWorkloadStructures::class)();

        $this->assertSame(0, $stats['structures_created'] + $stats['structures_updated']);
        $this->assertCount(1, $stats['skipped']);
        $this->assertNull($local->fresh()->external_id);
        $this->assertSame(['กลุ่มที่สร้างเอง'], $local->workloadGroups()->pluck('name')->all());
    }

    public function test_unknown_fiscal_year_or_position_is_skipped(): void
    {
        Position::factory()->fromOracle('0901')->create(['code' => '0901']);

        $this->source->structureItems = [
            InMemoryWorkloadSourceReader::item(2599, '0901', 'G1', 'M1', 'W1'),
            $this->item('9999', 'G1', 'M1', 'W1'),
        ];

        $stats = app(SyncWorkloadStructures::class)();

        $this->assertCount(2, $stats['skipped']);
        $this->assertSame(0, WorkloadStructure::count());
    }

    public function test_oracle_structure_missing_from_source_is_deactivated(): void
    {
        Position::factory()->fromOracle('0901')->create(['code' => '0901']);
        Position::factory()->fromOracle('0501')->create(['code' => '0501']);
        $this->source->structureItems = [$this->item('0901', 'G', 'M', 'W'), $this->item('0501', 'G', 'M', 'W')];
        app(SyncWorkloadStructures::class)();

        $this->source->structureItems = [$this->item('0901', 'G', 'M', 'W')];
        $stats = app(SyncWorkloadStructures::class)();

        $this->assertSame(1, $stats['structures_deactivated']);
        $this->assertFalse(WorkloadStructure::firstWhere('external_id', '2569:0501')->is_active);
    }

    // --- staff positions --------------------------------------------------

    public function test_staff_positions_match_by_employee_id_then_email(): void
    {
        $computer = Position::factory()->fromOracle()->create(['code' => '0901']);
        $finance = Position::factory()->fromOracle()->create(['code' => '0501']);

        $byId = User::factory()->create(['role' => UserRole::Staff, 'external_id' => 'E1']);
        $byEmail = User::factory()->create(['role' => UserRole::Staff, 'email' => 'Somchai@SRU.ac.th']);

        $this->source->staffPositions = [
            new StaffPositionRow('E1', null, '0901'),
            new StaffPositionRow('E2', 'somchai@sru.ac.th', '0501'),
            new StaffPositionRow('E3', 'nobody@sru.ac.th', '0901'),
            new StaffPositionRow('E4', null, '7777'),
        ];

        $stats = app(SyncStaffPositions::class)();

        $this->assertSame(['updated' => 2, 'unchanged' => 0, 'skipped_manual' => 0, 'unmatched' => 2, 'unknown_position' => 0], $stats);
        $this->assertSame($computer->id, $byId->fresh()->position_id);
        $this->assertSame($finance->id, $byEmail->fresh()->position_id);
        $this->assertSame('E2', $byEmail->fresh()->external_id);
        $this->assertSame(PositionSource::Oracle, $byEmail->fresh()->position_source);
    }

    public function test_staff_matched_by_vnmis_alternate_email(): void
    {
        $position = Position::factory()->fromOracle()->create(['code' => '0901']);
        $user = User::factory()->create(['role' => UserRole::Staff, 'email' => 'somchai.s@sru.ac.th']);

        $this->source->staffPositions = [
            new StaffPositionRow('2519', 'somchai@gmail.com', '0901', altEmail: 'somchai.s@sru.ac.th'),
        ];
        app(SyncStaffPositions::class)();

        $this->assertSame($position->id, $user->fresh()->position_id);
        $this->assertSame('2519', $user->fresh()->external_id);
    }

    public function test_manual_position_is_never_overwritten(): void
    {
        Position::factory()->fromOracle()->create(['code' => '0901']);
        $manual = Position::factory()->create();
        $user = User::factory()->create([
            'role' => UserRole::Staff, 'external_id' => 'E1', 'position_id' => $manual->id, 'position_source' => PositionSource::Manual,
        ]);

        $this->source->staffPositions = [new StaffPositionRow('E1', null, '0901')];
        $stats = app(SyncStaffPositions::class)();

        $this->assertSame(1, $stats['skipped_manual']);
        $this->assertSame($manual->id, $user->fresh()->position_id);
    }

    public function test_newest_fiscal_year_row_wins(): void
    {
        Position::factory()->fromOracle()->create(['code' => '0901']);
        $newer = Position::factory()->fromOracle()->create(['code' => '0902']);
        $user = User::factory()->create(['role' => UserRole::Staff, 'external_id' => 'E1']);

        $this->source->staffPositions = [
            new StaffPositionRow('E1', null, '0902', 2569),
            new StaffPositionRow('E1', null, '0901', 2568),
        ];
        app(SyncStaffPositions::class)();

        $this->assertSame($newer->id, $user->fresh()->position_id);
    }

    // --- orchestration ----------------------------------------------------

    public function test_full_sync_runs_in_order_and_logs_the_run(): void
    {
        $this->source->positions = [new PositionRow('0901', '0901', 'นักวิชาการคอมพิวเตอร์')];
        $this->source->structureItems = [$this->item('0901', 'G', 'M', 'W')];
        $this->source->staffPositions = [new StaffPositionRow(null, 'staff@sru.ac.th', '0901')];
        $user = User::factory()->create(['role' => UserRole::Staff, 'email' => 'staff@sru.ac.th']);

        $run = app(RunOracleSync::class)();

        $this->assertSame(OracleSyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->stats['positions']['created']);
        $this->assertSame(1, $run->stats['structures']['structures_created']);
        $this->assertSame(1, $run->stats['staff']['updated']);
        $this->assertNotNull(WorkloadStructure::forUserAndFiscalYear($user->fresh(), FiscalYear::first()->id));
    }

    public function test_dry_run_reports_changes_without_saving(): void
    {
        $this->source->positions = [new PositionRow('0901', '0901', 'นักวิชาการคอมพิวเตอร์')];
        $this->source->structureItems = [$this->item('0901', 'G', 'M', 'W')];

        $this->artisan('oracle:sync', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Position::count());
        $this->assertSame(0, WorkloadStructure::count());

        $run = OracleSyncRun::sole();
        $this->assertTrue($run->dry_run);
        $this->assertSame(1, $run->stats['positions']['created']);
    }

    public function test_second_run_is_blocked_while_one_holds_the_lock(): void
    {
        $lock = Cache::lock('oracle-sync', 60);
        $lock->get();

        $run = app(RunOracleSync::class)();

        $this->assertSame(OracleSyncRun::STATUS_FAILED, $run->status);
        $lock->release();
    }

    public function test_failure_is_recorded_and_rolled_back(): void
    {
        // Positions succeed, then structures blow up: the whole run must roll back.
        $failing = new class extends InMemoryWorkloadSourceReader
        {
            public function structureItems(?int $fiscalYear = null): iterable
            {
                throw new \RuntimeException('ORA-00942: table or view does not exist');
            }
        };
        $failing->positions = [new PositionRow('0901', '0901', 'x')];
        $this->app->instance(WorkloadSourceReader::class, $failing);

        $this->artisan('oracle:sync')->assertFailed();

        $run = OracleSyncRun::sole();
        $this->assertSame(OracleSyncRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('ORA-00942', $run->error);
        $this->assertSame(0, Position::count());
    }

    public function test_unknown_only_option_is_rejected(): void
    {
        $this->artisan('oracle:sync', ['--only' => 'positions,bogus'])->assertExitCode(2);
    }
}
