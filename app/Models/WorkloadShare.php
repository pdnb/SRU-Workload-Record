<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WorkloadShare extends Model
{
    protected $fillable = [
        'work_record_id',
        'workload_id',
        'token',
        'enabled_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled_at' => 'datetime',
        ];
    }

    public function workRecord(): BelongsTo
    {
        return $this->belongsTo(WorkRecord::class);
    }

    public function workload(): BelongsTo
    {
        return $this->belongsTo(Workload::class);
    }

    public function isEnabled(): bool
    {
        return $this->enabled_at !== null;
    }

    public function shareUrl(): string
    {
        return route('share.workload.show', $this->token);
    }

    public function regenerateToken(): void
    {
        $this->token = (string) Str::uuid();
    }
}
