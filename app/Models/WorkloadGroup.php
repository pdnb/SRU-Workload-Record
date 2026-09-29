<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkloadGroup extends Model
{
    protected $fillable = ['workload_structure_id', 'external_id', 'name', 'max_score', 'sort_order', 'is_active', 'synced_at'];

    protected function casts(): array
    {
        return [
            'max_score' => 'decimal:2',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(WorkloadStructure::class, 'workload_structure_id');
    }

    public function workloadMains(): HasMany
    {
        return $this->hasMany(WorkloadMain::class)->orderBy('sort_order');
    }

    public function scopeForStructure($query, ?int $structureId)
    {
        return $query->where('workload_structure_id', $structureId)->orderBy('sort_order');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Eager-load the main/workload tree, skipping items deactivated by an Oracle sync.
     */
    public function scopeWithActiveTree($query)
    {
        return $query->active()->with([
            'workloadMains' => fn ($q) => $q->where('is_active', true),
            'workloadMains.workloads' => fn ($q) => $q->where('is_active', true),
        ]);
    }
}
