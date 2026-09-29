<?php

namespace App\Console\Commands;

use App\Actions\Oracle\RunOracleSync;
use App\Models\OracleSyncRun;
use Illuminate\Console\Command;

class OracleSync extends Command
{
    protected $signature = 'oracle:sync
        {--only= : Comma-separated parts to run: positions,structures,staff}
        {--year= : Fiscal year (Buddhist era, e.g. 2569) to sync structures for}
        {--dry-run : Show what would change without saving}';

    protected $description = 'Sync positions, per-position workload structures and staff positions from Oracle';

    public function handle(RunOracleSync $sync): int
    {
        $only = $this->option('only')
            ? array_map('trim', explode(',', (string) $this->option('only')))
            : null;

        if ($only !== null && ($unknown = array_diff($only, RunOracleSync::PARTS)) !== []) {
            $this->error('ไม่รู้จัก --only: '.implode(', ', $unknown).' (ใช้ได้: '.implode(', ', RunOracleSync::PARTS).')');

            return self::INVALID;
        }

        $year = $this->option('year') !== null ? (int) $this->option('year') : null;
        $dryRun = (bool) $this->option('dry-run');

        $run = $sync(only: $only, year: $year, dryRun: $dryRun);

        $this->renderStats($run->stats ?? []);

        if ($run->status !== OracleSyncRun::STATUS_SUCCESS) {
            $this->error('ซิงก์ไม่สำเร็จ: '.$run->error);

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Dry run — ไม่มีการบันทึกข้อมูล' : 'ซิงก์เรียบร้อย');

        return self::SUCCESS;
    }

    protected function renderStats(array $stats): void
    {
        foreach ($stats as $part => $values) {
            $this->newLine();
            $this->line("<comment>{$part}</comment>");

            $skipped = $values['skipped'] ?? [];
            unset($values['skipped']);

            $this->table(['รายการ', 'จำนวน'], collect($values)->map(fn ($v, $k) => [$k, $v])->values()->all());

            foreach ($skipped as $message) {
                $this->warn("  ข้าม {$message}");
            }
        }
    }
}
