<?php

namespace App\Http\Controllers;

use App\Exports\WorkRecordsExport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WorkRecordsExportController
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()?->isHrOrAdmin(), 403);

        $fiscalYear = $request->integer('fiscal_year') ?: null;
        $period = $request->integer('period') ?: null;
        $department = $request->string('department')->toString() ?: null;

        if ($department === '') {
            $department = null;
        }

        return Excel::download(
            new WorkRecordsExport($fiscalYear, $period, $department),
            'work-records-'.now()->format('Y-m-d').'.xlsx',
        );
    }
}
