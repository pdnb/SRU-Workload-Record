<?php

namespace App\Models;

use App\Enums\EvidenceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class WorkEntryEvidence extends Model
{
    protected $table = 'work_entry_evidences';

    protected $fillable = [
        'work_entry_id',
        'type',
        'value',
        'filename',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'type' => EvidenceType::class,
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (WorkEntryEvidence $evidence) {
            if ($evidence->type === EvidenceType::File) {
                Storage::disk('public')->delete($evidence->value);
            }
        });
    }

    public function workEntry(): BelongsTo
    {
        return $this->belongsTo(WorkEntry::class);
    }

    public function displayName(): string
    {
        if (filled($this->filename)) {
            return $this->filename;
        }

        return basename($this->value);
    }

    public function url(): ?string
    {
        if ($this->type === EvidenceType::Url) {
            return $this->value;
        }

        return Storage::disk('public')->url($this->value);
    }

    public function previewRoute(): ?string
    {
        if ($this->type !== EvidenceType::File || ! self::isPathPreviewable($this->value)) {
            return null;
        }

        return route('staff.evidence.preview', $this);
    }

    public function downloadRoute(): string
    {
        return route('staff.evidence.download', $this);
    }

    public function publicPreviewRoute(string $token): ?string
    {
        if ($this->type !== EvidenceType::File || ! self::isPathPreviewable($this->value)) {
            return null;
        }

        return route('share.workload.evidence.preview', ['token' => $token, 'evidence' => $this]);
    }

    public function publicDownloadRoute(string $token): string
    {
        return route('share.workload.evidence.download', ['token' => $token, 'evidence' => $this]);
    }

    public static function isPathPreviewable(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), [
            'jpg',
            'jpeg',
            'png',
            'webp',
            'pdf',
        ], true);
    }
}
