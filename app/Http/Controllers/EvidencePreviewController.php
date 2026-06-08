<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesEvidenceFile;
use App\Models\WorkEntryEvidence;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EvidencePreviewController
{
    use ResolvesEvidenceFile;

    public function __invoke(WorkEntryEvidence $evidence): BinaryFileResponse
    {
        $path = $this->resolveEvidenceFilePath($evidence);

        return response()->file(
            Storage::disk('public')->path($path),
            ['Content-Disposition' => 'inline; filename="'.$evidence->displayName().'"'],
        );
    }
}
