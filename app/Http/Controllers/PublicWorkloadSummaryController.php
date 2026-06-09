<?php

namespace App\Http\Controllers;

use App\Actions\CalculateWorkRecordScore;
use App\Actions\ResolvePublicWorkloadShare;
use App\Models\WorkloadGroup;
use App\Support\WorkloadStructureNumbering;
use Illuminate\View\View;

class PublicWorkloadSummaryController
{
    public function __invoke(string $token, ResolvePublicWorkloadShare $resolveShare): View
    {
        $share = $resolveShare($token);

        $share->load([
            'workRecord.user',
            'workRecord.evaluationPeriod.fiscalYear',
            'workload.workloadMain.workloadGroup',
        ]);

        $record = $share->workRecord;

        $entries = $record->workEntries()
            ->where('workload_id', $share->workload_id)
            ->with(['fileEvidences', 'urlEvidence', 'workload'])
            ->orderBy('sort_order')
            ->get();

        $groups = WorkloadGroup::query()
            ->forFiscalYear($record->evaluationPeriod->fiscal_year_id)
            ->with(['workloadMains.workloads'])
            ->get();

        $structureNumbers = WorkloadStructureNumbering::buildMap($groups);

        $scoreCalculator = app(CalculateWorkRecordScore::class);
        $earnedScore = $entries->sum(fn ($entry) => $scoreCalculator->entryScore($entry));

        $workload = $share->workload;
        $main = $workload->workloadMain;
        if ($main->max_score !== null) {
            $earnedScore = min($earnedScore, (float) $main->max_score);
        }

        return view('public.workload-summary', [
            'share' => $share,
            'record' => $record,
            'workload' => $workload,
            'entries' => $entries,
            'structureNumbers' => $structureNumbers,
            'earnedScore' => $earnedScore,
            'totalHours' => (float) $entries->sum('hours'),
        ]);
    }
}
