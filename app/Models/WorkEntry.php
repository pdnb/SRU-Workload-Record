<?php

namespace App\Models;

use App\Enums\EvidenceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class WorkEntry extends Model
{
    protected $fillable = [
        'work_record_id',
        'workload_id',
        'detail',
        'result',
        'hours',
        'evidence',
        'evidence_type',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'hours' => 'decimal:1',
            'evidence_type' => EvidenceType::class,
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (WorkEntry $entry) {
            if ($entry->evidence_type === EvidenceType::File) {
                foreach ($entry->evidenceFilePaths() as $path) {
                    Storage::disk('public')->delete($path);
                }
            }
        });
    }

    /**
     * @return list<string>
     */
    public function evidenceFilePaths(): array
    {
        if ($this->evidence_type !== EvidenceType::File || blank($this->evidence)) {
            return [];
        }

        $decoded = json_decode($this->evidence, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return array_values(array_filter($decoded, fn ($path) => is_string($path) && $path !== ''));
        }

        return [$this->evidence];
    }

    public function workRecord(): BelongsTo
    {
        return $this->belongsTo(WorkRecord::class);
    }

    public function workload(): BelongsTo
    {
        return $this->belongsTo(Workload::class);
    }

    public function hasEvidence(): bool
    {
        if ($this->evidence_type === EvidenceType::File) {
            return $this->evidenceFilePaths() !== [];
        }

        return filled($this->evidence);
    }

    public function evidenceUrl(): ?string
    {
        if (! $this->hasEvidence()) {
            return null;
        }

        if ($this->evidence_type === EvidenceType::File) {
            $first = $this->evidenceFilePaths()[0] ?? null;

            return $first ? Storage::disk('public')->url($first) : null;
        }

        return $this->evidence;
    }
}
