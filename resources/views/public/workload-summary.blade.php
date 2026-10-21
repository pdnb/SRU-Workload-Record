<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>สรุปผลงาน — {{ $record->user->name }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Sarabun', 'Tahoma', sans-serif;
            font-size: 15px;
            line-height: 1.6;
            color: #18181b;
            background: #f4f4f5;
            padding: 16px;
        }
        .container {
            max-width: 720px;
            margin: 0 auto;
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e4e4e7;
            overflow: hidden;
        }
        .header {
            padding: 24px;
            border-bottom: 1px solid #e4e4e7;
            background: #fafafa;
        }
        h1 { font-size: 22px; margin-bottom: 4px; }
        .subtitle { color: #71717a; font-size: 14px; }
        .meta { margin-top: 16px; font-size: 14px; color: #52525b; }
        .meta p { margin: 4px 0; }
        .section { padding: 24px; }
        .breadcrumb {
            font-size: 13px;
            color: #71717a;
            margin-bottom: 8px;
        }
        .breadcrumb .number { font-variant-numeric: tabular-nums; }
        h2 { font-size: 18px; margin-bottom: 12px; }
        .stats {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 13px;
            background: #f4f4f5;
            color: #3f3f46;
        }
        .badge-purple { background: #f3e8ff; color: #7e22ce; }
        .badge-blue { background: #dbeafe; color: #1d4ed8; }
        .entry {
            margin-bottom: 16px;
            padding: 16px;
            border: 1px solid #e4e4e7;
            border-radius: 8px;
            background: #fafafa;
        }
        .entry-detail { margin-top: 8px; }
        .entry-detail p { margin: 0 0 4px; }
        .entry-detail ul, .entry-detail ol { margin: 4px 0; padding-left: 24px; }
        .entry-detail a { color: #2563eb; }
        .entry-result { margin-top: 8px; font-size: 14px; color: #52525b; }
        .entry-meta { margin-top: 8px; font-size: 13px; color: #71717a; }
        .evidence-list { margin-top: 8px; padding-left: 0; list-style: none; }
        .evidence-list li { margin-top: 4px; }
        .evidence-list a { color: #2563eb; text-decoration: none; }
        .evidence-list a:hover { text-decoration: underline; }
        .footer {
            padding: 16px 24px;
            border-top: 1px solid #e4e4e7;
            font-size: 12px;
            color: #a1a1aa;
            text-align: center;
        }
        @media (min-width: 640px) {
            body { padding: 32px 16px; }
        }
    </style>
</head>
<body>
    @php
        $group = $workload->workloadMain->workloadGroup;
        $main = $workload->workloadMain;
        $groupNum = $structureNumbers['groups'][$group->id] ?? '';
        $mainNum = $structureNumbers['mains'][$main->id] ?? '';
        $workloadNum = $structureNumbers['workloads'][$workload->id] ?? '';
    @endphp

    <div class="container">
        <div class="header">
            <h1>สรุปผลงานภาระงาน</h1>
            <p class="subtitle">ระบบบันทึกการปฏิบัติงาน</p>
            <div class="meta">
                <p><strong>{{ $record->user->name }}</strong></p>
                @if ($record->user->position || $record->user->department)
                    <p>{{ collect([$record->user->position, $record->user->department])->filter()->join(' · ') }}</p>
                @endif
                <p>{{ $record->evaluationPeriod->label }} (ปีงบประมาณ {{ $record->evaluationPeriod->fiscalYear->year }})</p>
            </div>
        </div>

        <div class="section">
            <p class="breadcrumb">
                <span class="number">{{ $groupNum }}</span> {{ $group->name }}
                <span aria-hidden="true"> › </span>
                <span class="number">{{ $mainNum }}</span> {{ $main->name }}
            </p>
            <h2>
                <span class="number">{{ $workloadNum }}</span>
                {{ $workload->name }}
            </h2>

            <div class="stats">
                <span class="badge badge-blue">{{ $entries->count() }} ผลงาน</span>
                @if ($totalHours > 0)
                    <span class="badge">{{ number_format($totalHours, 1) }} งาน</span>
                @endif
                @if ($earnedScore > 0)
                    <span class="badge badge-purple">{{ number_format($earnedScore, 2) }} คะแนน</span>
                @endif
                @if ($workload->score !== null)
                    <span class="badge">หน่วยละ {{ number_format($workload->score, 2) }} คะแนน</span>
                @endif
            </div>

            @forelse ($entries as $entry)
                <div class="entry">
                    <div class="entry-detail">{!! \App\Support\RichText::render($entry->detail) !!}</div>
                    @if ($entry->result)
                        <div class="entry-result"><strong>ผลลัพธ์:</strong> {{ $entry->result }}</div>
                    @endif
                    @if ($entry->hours)
                        <div class="entry-meta">{{ $entry->hours }} งาน</div>
                    @endif
                    @if ($entry->hasEvidence())
                        <ul class="evidence-list">
                            @foreach ($entry->fileEvidences as $fileEvidence)
                                <li>
                                    @if ($previewUrl = $fileEvidence->publicPreviewRoute($share->token))
                                        <a href="{{ $previewUrl }}" target="_blank" rel="noopener noreferrer">
                                            {{ $fileEvidence->displayName() }}
                                        </a>
                                    @else
                                        <a href="{{ $fileEvidence->publicDownloadRoute($share->token) }}">
                                            {{ $fileEvidence->displayName() }}
                                        </a>
                                    @endif
                                </li>
                            @endforeach
                            @if ($entry->urlEvidence)
                                <li>
                                    <a href="{{ $entry->urlEvidence->value }}" target="_blank" rel="noopener noreferrer">
                                        {{ $entry->urlEvidence->value }}
                                    </a>
                                </li>
                            @endif
                        </ul>
                    @endif
                </div>
            @empty
                <p class="subtitle">ไม่มีรายการผลงาน</p>
            @endforelse
        </div>

        <div class="footer">
            สรุปจากระบบบันทึกการปฏิบัติงาน — ลิงก์นี้แชร์โดยเจ้าของบันทึก
        </div>
    </div>
</body>
</html>
