<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkloadGroup extends Model
{
    protected $fillable = ['fiscal_year_id', 'name', 'max_score', 'sort_order'];

    protected function casts(): array
    {
        return [
            'max_score' => 'decimal:2',
        ];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function workloadMains(): HasMany
    {
        return $this->hasMany(WorkloadMain::class)->orderBy('sort_order');
    }

    public function scopeForFiscalYear($query, int $fiscalYearId)
    {
        return $query->where('fiscal_year_id', $fiscalYearId)->orderBy('sort_order');
    }
}
