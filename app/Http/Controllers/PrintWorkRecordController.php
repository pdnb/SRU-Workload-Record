<?php

namespace App\Http\Controllers;

use App\Models\WorkRecord;
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
        ]);

        return view('staff.print-record', ['record' => $record]);
    }
}
