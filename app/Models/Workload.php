<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workload extends Model
{
    protected $fillable = ['workload_main_id', 'name', 'score', 'sort_order'];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
        ];
    }

    public function workloadMain(): BelongsTo
    {
        return $this->belongsTo(WorkloadMain::class);
    }

    public function workEntries(): HasMany
    {
        return $this->hasMany(WorkEntry::class);
    }

    public function workloadShares(): HasMany
    {
        return $this->hasMany(WorkloadShare::class);
    }
}
