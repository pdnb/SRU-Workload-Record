<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesPublicEvidenceFile;
use App\Models\WorkEntryEvidence;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicEvidencePreviewController
{
    use ResolvesPublicEvidenceFile;

    public function __invoke(string $token, WorkEntryEvidence $evidence): BinaryFileResponse
    {
        $path = $this->resolvePublicEvidenceFilePath($token, $evidence);

        return response()->file(
            Storage::disk('public')->path($path),
            ['Content-Disposition' => 'inline; filename="'.$evidence->displayName().'"'],
        );
    }
}
