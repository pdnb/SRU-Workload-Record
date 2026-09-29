<?php

namespace App\Http\Controllers;

use App\Models\WorkloadGroup;
use App\Models\WorkRecord;
use App\Support\WorkloadStructureNumbering;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PrintWorkRecordController
{
    public function __invoke(WorkRecord $record): View
    {
        Gate::authorize('view', $record);

        $record->load([
            'user',
            'evaluationPeriod.fiscalYear',
            'workEntries.workload.workloadMain.workloadGroup',
            'workEntries.fileEvidences',
            'workEntries.urlEvidence',
        ]);

        $groups = WorkloadGroup::query()
            ->forStructure($record->resolvedStructureId())
            ->with(['workloadMains.workloads'])
            ->get();

        $structureNumbers = WorkloadStructureNumbering::buildMap($groups);

        return view('staff.print-record', [
            'record' => $record,
            'structureNumbers' => $structureNumbers,
        ]);
    }
}
