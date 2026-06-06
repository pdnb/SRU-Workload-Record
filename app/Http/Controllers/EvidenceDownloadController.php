<?php

namespace App\Http\Controllers;

use App\Enums\EvidenceType;
use App\Models\WorkEntry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenceDownloadController
{
    public function __invoke(WorkEntry $entry, int $fileIndex = 0): StreamedResponse
    {
        Gate::authorize('view', $entry->workRecord);

        abort_unless($entry->evidence_type === EvidenceType::File, 404);

        $paths = $entry->evidenceFilePaths();
        $path = $paths[$fileIndex] ?? null;

        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->download($path);
    }
}
