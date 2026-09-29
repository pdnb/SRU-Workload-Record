<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class FiscalYear extends Model
{
    protected $fillable = ['year', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function evaluationPeriods(): HasMany
    {
        return $this->hasMany(EvaluationPeriod::class);
    }

    public function workloadStructures(): HasMany
    {
        return $this->hasMany(WorkloadStructure::class);
    }

    public function workloadGroups(): HasManyThrough
    {
        return $this->hasManyThrough(WorkloadGroup::class, WorkloadStructure::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
