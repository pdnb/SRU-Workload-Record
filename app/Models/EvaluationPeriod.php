<?php

namespace App\Models;

use App\Enums\EvaluationPeriodNumber;
use App\Enums\PeriodStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationPeriod extends Model
{
    protected $fillable = [
        'fiscal_year_id',
        'period',
        'label',
        'start_date',
        'end_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'period' => EvaluationPeriodNumber::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => PeriodStatus::class,
        ];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function workRecords(): HasMany
    {
        return $this->hasMany(WorkRecord::class);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function scopeOpen($query)
    {
        return $query->where('status', PeriodStatus::Open);
    }
}
