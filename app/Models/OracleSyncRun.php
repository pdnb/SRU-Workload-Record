<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OracleSyncRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['type', 'status', 'dry_run', 'started_at', 'finished_at', 'stats', 'error'];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'stats' => 'array',
        ];
    }
}
