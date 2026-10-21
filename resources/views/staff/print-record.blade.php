<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>พิมพ์บันทึกภาระงาน — {{ $record->user->name }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Sarabun', 'Tahoma', sans-serif; font-size: 14px; line-height: 1.6; color: #111; padding: 24px; }
        h1 { font-size: 20px; margin-bottom: 4px; }
        h2 { font-size: 16px; margin: 24px 0 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
        h3 { font-size: 14px; margin: 16px 0 6px; color: #333; }
        .meta { color: #555; font-size: 13px; margin-bottom: 20px; }
        .meta p { margin: 2px 0; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; background: #e5e5e5; }
        .entry { margin-bottom: 12px; padding: 10px; border: 1px solid #ddd; border-radius: 4px; page-break-inside: avoid; }
        .entry-detail p { margin: 0 0 4px; }
        .entry-detail ul, .entry-detail ol { margin: 4px 0; padding-left: 24px; }
        .entry-detail a { color: #2563eb; }
        .entry-meta { font-size: 12px; color: #666; margin-top: 6px; }
        .summary { margin-top: 24px; padding: 12px; background: #f5f5f5; border-radius: 4px; }
        @media print {
            body { padding: 0; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px;">
        <button onclick="window.print()" style="padding: 8px 16px; cursor: pointer;">พิมพ์</button>
    </div>

    <h1>บันทึกภาระงาน</h1>
    <p class="meta">
        <strong>{{ $record->user->name }}</strong><br>
        {{ $record->user->position }} · {{ $record->user->department }}<br>
        {{ $record->evaluationPeriod->label }} (ปีงบประมาณ {{ $record->evaluationPeriod->fiscalYear->year }})<br>
        สถานะ: <span class="badge">{{ $record->status->label() }}</span>
        @if ($record->submitted_at)
            · ส่งเมื่อ {{ $record->submitted_at->format('d/m/Y H:i') }}
        @endif
    </p>

    @php
        $grouped = $record->workEntries->groupBy(function ($e) use ($structureNumbers) {
            $main = $e->workload->workloadMain;
            $group = $main->workloadGroup;
            $groupNum = $structureNumbers['groups'][$group->id] ?? '';
            $mainNum = $structureNumbers['mains'][$main->id] ?? '';

            return $groupNum.' › '.$mainNum.' '.$group->name.' › '.$main->name;
        });
    @endphp

    <h2>รายการผลงาน</h2>

    @forelse ($grouped as $section => $entries)
        <h3>{{ $section }}</h3>
        @foreach ($entries as $entry)
            <div class="entry">
                <strong>{{ $structureNumbers['workloads'][$entry->workload->id] ?? '' }} {{ $entry->workload->name }}</strong>
                @if ($entry->workload->score !== null)
                    <span class="badge" style="margin-left: 8px;">{{ number_format($entry->workload->score, 2) }} คะแนน</span>
                @endif
                <div class="entry-detail">{!! \App\Support\RichText::render($entry->detail) !!}</div>
                @if ($entry->result)
                    <div class="entry-meta"><strong>ผลลัพธ์:</strong> {{ $entry->result }}</div>
                @endif
                <div class="entry-meta">
                    @if ($entry->hours)
                        {{ $entry->hours }} งาน
                    @endif
                    @if ($entry->hasEvidence())
                        ·
                        @if ($entry->fileEvidences->isNotEmpty())
                            หลักฐาน: {{ $entry->fileEvidences->map->displayName()->join(', ') }}
                        @elseif ($entry->urlEvidence)
                            หลักฐาน: {{ $entry->urlEvidence->value }}
                        @endif
                    @endif
                </div>
            </div>
        @endforeach
    @empty
        <p>ไม่มีรายการผลงาน</p>
    @endforelse

    <p class="meta" style="margin-top: 32px;">
        รวม {{ $record->workEntries->count() }} รายการ · {{ number_format($record->workEntries->sum('hours'), 1) }} งาน
    </p>
</body>
</html>
