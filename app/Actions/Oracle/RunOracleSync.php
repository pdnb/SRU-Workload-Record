<?php

namespace App\Actions\Oracle;

use App\Models\OracleSyncRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunOracleSync
{
    public const PARTS = ['positions', 'structures', 'staff'];

    private const LOCK_KEY = 'oracle-sync';

    private const LOCK_SECONDS = 1800;

    public function __construct(
        private SyncPositions $syncPositions,
        private SyncWorkloadStructures $syncStructures,
        private SyncStaffPositions $syncStaff,
    ) {}

    /**
     * Runs the requested parts in dependency order (positions → structures → staff)
     * and records the outcome in oracle_sync_runs. A dry run executes everything
     * inside a transaction that is rolled back, so its stats are exact.
     *
     * @param  list<string>|null  $only
     */
    public function __invoke(?array $only = null, ?int $year = null, bool $dryRun = false): OracleSyncRun
    {
        $parts = array_values(array_intersect(self::PARTS, $only ?? self::PARTS));

        $run = OracleSyncRun::create([
            'type' => implode(',', $parts),
            'status' => OracleSyncRun::STATUS_RUNNING,
            'dry_run' => $dryRun,
            'started_at' => now(),
        ]);

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return $this->finish($run, OracleSyncRun::STATUS_FAILED, [], 'มีการซิงก์อื่นกำลังทำงานอยู่');
        }

        $stats = [];

        try {
            DB::beginTransaction();

            if (in_array('positions', $parts, true)) {
                $stats['positions'] = ($this->syncPositions)();
            }

            if (in_array('structures', $parts, true)) {
                $stats['structures'] = ($this->syncStructures)($year);
            }

            if (in_array('staff', $parts, true)) {
                $stats['staff'] = ($this->syncStaff)();
            }

            $dryRun ? DB::rollBack() : DB::commit();

            return $this->finish($run, OracleSyncRun::STATUS_SUCCESS, $stats);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Oracle sync failed.', ['run' => $run->id, 'exception' => $e]);

            return $this->finish($run, OracleSyncRun::STATUS_FAILED, $stats, $e->getMessage());
        } finally {
            $lock->release();
        }
    }

    protected function finish(OracleSyncRun $run, string $status, array $stats, ?string $error = null): OracleSyncRun
    {
        $run->update([
            'status' => $status,
            'stats' => $stats,
            'error' => $error,
            'finished_at' => now(),
        ]);

        return $run;
    }
}
