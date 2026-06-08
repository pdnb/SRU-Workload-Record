<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesEvidenceFile;
use App\Models\WorkEntryEvidence;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenceDownloadController
{
    use ResolvesEvidenceFile;

    public function __invoke(WorkEntryEvidence $evidence): StreamedResponse
    {
        $path = $this->resolveEvidenceFilePath($evidence);

        return Storage::disk('public')->download($path, $evidence->displayName());
    }
}
