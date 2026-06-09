<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesPublicEvidenceFile;
use App\Models\WorkEntryEvidence;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicEvidenceDownloadController
{
    use ResolvesPublicEvidenceFile;

    public function __invoke(string $token, WorkEntryEvidence $evidence): StreamedResponse
    {
        $path = $this->resolvePublicEvidenceFilePath($token, $evidence);

        return Storage::disk('public')->download($path, $evidence->displayName());
    }
}
