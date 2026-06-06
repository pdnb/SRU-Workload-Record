<?php

namespace App\Models;

use App\Actions\CalculateWorkRecordScore;
use App\Data\WorkRecordScoreSummary;
use App\Enums\PeriodStatus;
use App\Enums\WorkRecordStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkRecord extends Model
{
    protected $fillable = [
        'user_id',
        'evaluation_period_id',
        'status',
        'self_assessment',
        'self_score',
        'submitted_at',
        'approved_at',
        'rejection_comment',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkRecordStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function evaluationPeriod(): BelongsTo
    {
        return $this->belongsTo(EvaluationPeriod::class);
    }

    public function workEntries(): HasMany
    {
        return $this->hasMany(WorkEntry::class)->orderBy('sort_order');
    }

    public function isEditable(): bool
    {
        if (! in_array($this->status, [WorkRecordStatus::Draft, WorkRecordStatus::Rejected])) {
            return false;
        }

        return $this->evaluationPeriod?->status === PeriodStatus::Open;
    }

    public function totalEntries(): int
    {
        return $this->workEntries()->count();
    }

    public function totalHours(): float
    {
        return (float) $this->workEntries()->sum('hours');
    }

    public function scoreSummary(): WorkRecordScoreSummary
    {
        return app(CalculateWorkRecordScore::class)($this);
    }
}
