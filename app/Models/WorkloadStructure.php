<?php

namespace App\Models;

use Database\Factories\WorkloadStructureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkloadStructure extends Model
{
    /** @use HasFactory<WorkloadStructureFactory> */
    use HasFactory;

    protected $fillable = ['external_id', 'fiscal_year_id', 'position_id', 'name', 'is_active', 'synced_at'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function workloadGroups(): HasMany
    {
        return $this->hasMany(WorkloadGroup::class)->orderBy('sort_order');
    }

    public function workRecords(): HasMany
    {
        return $this->hasMany(WorkRecord::class);
    }

    public function isFromOracle(): bool
    {
        return $this->external_id !== null;
    }

    public static function forUserAndFiscalYear(User $user, int $fiscalYearId): ?self
    {
        if ($user->position_id === null) {
            return null;
        }

        return static::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('position_id', $user->position_id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Ids of every workload that belongs to this structure.
     *
     * @return list<int>
     */
    public function workloadIds(): array
    {
        return Workload::query()
            ->whereHas('workloadMain.workloadGroup', fn ($q) => $q->where('workload_structure_id', $this->id))
            ->pluck('id')
            ->all();
    }
}
