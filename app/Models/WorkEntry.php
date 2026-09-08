<?php

namespace App\Models;

use App\Enums\EvidenceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class WorkEntry extends Model
{
    protected $fillable = [
        'work_record_id',
        'source_work_entry_id',
        'workload_id',
        'detail',
        'result',
        'hours',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'hours' => 'decimal:1',
        ];
    }

    public function workRecord(): BelongsTo
    {
        return $this->belongsTo(WorkRecord::class);
    }

    public function sourceEntry(): BelongsTo
    {
        return $this->belongsTo(WorkEntry::class, 'source_work_entry_id');
    }

    public function mirroredEntries(): HasMany
    {
        return $this->hasMany(WorkEntry::class, 'source_work_entry_id');
    }

    public function isMirror(): bool
    {
        return $this->source_work_entry_id !== null;
    }

    public function workload(): BelongsTo
    {
        return $this->belongsTo(Workload::class);
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(WorkEntryEvidence::class)->orderBy('sort_order');
    }

    public function fileEvidences(): HasMany
    {
        return $this->hasMany(WorkEntryEvidence::class)
            ->where('type', EvidenceType::File)
            ->orderBy('sort_order');
    }

    public function urlEvidence(): HasOne
    {
        return $this->hasOne(WorkEntryEvidence::class)
            ->where('type', EvidenceType::Url);
    }

    /**
     * @return list<string>
     */
    public function evidenceFilePaths(): array
    {
        return $this->fileEvidences->pluck('value')->all();
    }

    public function hasEvidence(): bool
    {
        return $this->evidences()->exists();
    }

    public function evidenceUrl(): ?string
    {
        if ($this->urlEvidence) {
            return $this->urlEvidence->value;
        }

        $firstFile = $this->fileEvidences->first();

        return $firstFile ? Storage::disk('public')->url($firstFile->value) : null;
    }

    public static function isEvidencePathPreviewable(string $path): bool
    {
        return WorkEntryEvidence::isPathPreviewable($path);
    }

    public function evidencePreviewRoute(int $fileIndex): ?string
    {
        $evidence = $this->fileEvidences[$fileIndex] ?? null;

        return $evidence?->previewRoute();
    }
}
