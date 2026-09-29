<?php

namespace App\Console\Commands;

use App\Services\Oracle\OracleWorkloadReader;
use Illuminate\Console\Command;
use Throwable;

class OraclePing extends Command
{
    protected $signature = 'oracle:ping';

    protected $description = 'Check the read-only Oracle connection used for workload structure sync';

    public function handle(OracleWorkloadReader $reader): int
    {
        try {
            $result = $reader->ping();
        } catch (Throwable $e) {
            $this->error('เชื่อมต่อ Oracle ไม่สำเร็จ: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('เชื่อมต่อ Oracle สำเร็จ');
        $this->line($result['version'] ?? '(ไม่ทราบเวอร์ชัน)');

        return self::SUCCESS;
    }
}
