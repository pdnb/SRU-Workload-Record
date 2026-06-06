<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkloadMain extends Model
{
    protected $fillable = ['workload_group_id', 'name', 'max_score', 'sort_order'];

    protected function casts(): array
    {
        return [
            'max_score' => 'decimal:2',
        ];
    }

    public function workloadGroup(): BelongsTo
    {
        return $this->belongsTo(WorkloadGroup::class);
    }

    public function workloads(): HasMany
    {
        return $this->hasMany(Workload::class)->orderBy('sort_order');
    }
}
