<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\EvidenceType;
use App\Models\WorkEntryEvidence;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

trait ResolvesEvidenceFile
{
    protected function resolveEvidenceFilePath(WorkEntryEvidence $evidence): string
    {
        $evidence->loadMissing('workEntry.workRecord');

        Gate::authorize('view', $evidence->workEntry->workRecord);

        abort_unless($evidence->type === EvidenceType::File, 404);
        abort_unless(Storage::disk('public')->exists($evidence->value), 404);

        return $evidence->value;
    }
}
