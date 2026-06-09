<?php

namespace App\Http\Controllers\Concerns;

use App\Actions\ResolvePublicWorkloadShare;
use App\Enums\EvidenceType;
use App\Models\WorkEntryEvidence;
use Illuminate\Support\Facades\Storage;

trait ResolvesPublicEvidenceFile
{
    protected function resolvePublicEvidenceFilePath(string $token, WorkEntryEvidence $evidence): string
    {
        $share = app(ResolvePublicWorkloadShare::class)($token);

        abort_unless(
            app(ResolvePublicWorkloadShare::class)->evidenceBelongsToShare($share, $evidence),
            404,
        );

        abort_unless($evidence->type === EvidenceType::File, 404);
        abort_unless(Storage::disk('public')->exists($evidence->value), 404);

        return $evidence->value;
    }
}
