<?php

namespace App\Exports;

use App\Models\WorkRecord;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class WorkRecordsExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(
        protected ?int $fiscalYearId = null,
        protected ?int $evaluationPeriodId = null,
        protected ?string $department = null,
    ) {}

    public function collection()
    {
        return WorkRecord::with(['user', 'evaluationPeriod.fiscalYear', 'workEntries'])
            ->when($this->evaluationPeriodId, fn ($q) => $q->where('evaluation_period_id', $this->evaluationPeriodId))
            ->when($this->fiscalYearId && ! $this->evaluationPeriodId, fn ($q) => $q->whereHas(
                'evaluationPeriod',
                fn ($p) => $p->where('fiscal_year_id', $this->fiscalYearId),
            ))
            ->when($this->department, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('department', $this->department)))
            ->get();
    }

    public function headings(): array
    {
        return ['ชื่อ', 'หน่วยงาน', 'รอบประเมิน', 'สถานะ', 'จำนวนผลงาน', 'งานรวม', 'วันที่ส่ง'];
    }

    public function map($record): array
    {
        return [
            $record->user->name,
            $record->user->department,
            $record->evaluationPeriod->label,
            $record->status->label(),
            $record->workEntries->count(),
            $record->workEntries->sum('hours'),
            $record->submitted_at?->format('d/m/Y H:i') ?? '-',
        ];
    }
}
