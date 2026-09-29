<?php

use App\Actions\BuildWorkRecordChartData;
use App\Data\WorkRecordScoreSummary;
use App\Enums\PeriodStatus;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use App\Models\WorkEntry;
use App\Models\WorkRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?int $filterFiscalYearId = null;

    public ?int $filterPeriodId = null;

    public function mount(): void
    {
        $this->filterFiscalYearId = FiscalYear::query()->active()->value('id')
            ?? FiscalYear::query()->orderByDesc('year')->value('id');

        $this->filterPeriodId = $this->defaultPeriodIdForFiscalYear($this->filterFiscalYearId);
    }

    #[Computed]
    public function fiscalYears()
    {
        return FiscalYear::query()->orderByDesc('year')->get();
    }

    #[Computed]
    public function periods()
    {
        if (! $this->filterFiscalYearId) {
            return collect();
        }

        return EvaluationPeriod::query()
            ->where('fiscal_year_id', $this->filterFiscalYearId)
            ->orderBy('period')
            ->get();
    }

    #[Computed]
    public function currentRecord(): ?WorkRecord
    {
        if (! $this->filterPeriodId) {
            return null;
        }

        return Auth::user()
            ->workRecords()
            ->where('evaluation_period_id', $this->filterPeriodId)
            ->with(['workEntries.workload.workloadMain.workloadGroup'])
            ->first();
    }

    #[Computed]
    public function summary(): ?array
    {
        $record = $this->currentRecord;

        if (! $record) {
            return null;
        }

        $score = $record->scoreSummary();

        return [
            'entries' => $record->workEntries->count(),
            'hours' => (float) $record->workEntries->sum('hours'),
            'score' => $score,
        ];
    }

    #[Computed]
    public function chartData(): array
    {
        $record = $this->currentRecord;

        if (! $record) {
            return app(BuildWorkRecordChartData::class)->empty();
        }

        return app(BuildWorkRecordChartData::class)($record);
    }

    #[Computed]
    public function latestEntries()
    {
        $record = $this->currentRecord;

        if (! $record) {
            return collect();
        }

        return WorkEntry::query()
            ->where('work_record_id', $record->id)
            ->with(['workload.workloadMain.workloadGroup'])
            ->latest('updated_at')
            ->latest('id')
            ->limit(5)
            ->get();
    }

    public function updatedFilterFiscalYearId(): void
    {
        $this->filterPeriodId = $this->defaultPeriodIdForFiscalYear($this->filterFiscalYearId);
        $this->resetComputed();
    }

    public function updatedFilterPeriodId(): void
    {
        $this->resetComputed();
    }

    private function defaultPeriodIdForFiscalYear(?int $fiscalYearId): ?int
    {
        if (! $fiscalYearId) {
            return null;
        }

        return EvaluationPeriod::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('status', PeriodStatus::Open)
            ->orderByDesc('period')
            ->value('id')
            ?? EvaluationPeriod::query()
                ->where('fiscal_year_id', $fiscalYearId)
                ->orderByDesc('period')
                ->value('id');
    }

    private function resetComputed(): void
    {
        unset($this->periods, $this->currentRecord, $this->summary, $this->chartData, $this->latestEntries);
    }
}; ?>

<div>
    <div class="mb-4">
        <flux:heading size="xl">แดชบอร์ด</flux:heading>
        <flux:subheading>สรุปภาระงานของฉัน</flux:subheading>
    </div>

    <div class="mb-4 grid gap-4 sm:grid-cols-2">
        <flux:field>
            <flux:label>ปีงบประมาณ</flux:label>
            <x-searchable-select wire:model.live="filterFiscalYearId">
                @foreach ($this->fiscalYears as $fy)
                    <option wire:key="fy-opt-{{ $fy->id }}" value="{{ $fy->id }}">{{ $fy->year }}</option>
                @endforeach
            </x-searchable-select>
        </flux:field>

        <flux:field>
            <flux:label>รอบประเมิน</flux:label>
            <x-searchable-select wire:model.live="filterPeriodId">
                @foreach ($this->periods as $period)
                    <option wire:key="period-opt-{{ $period->id }}" value="{{ $period->id }}">{{ $period->label }}</option>
                @endforeach
            </x-searchable-select>
        </flux:field>
    </div>

    @if ($this->currentRecord)
        @php
            $summary = $this->summary;
            $score = $summary['score'];
            $chartData = $this->chartData;
        @endphp

        <div class="mb-4 grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="text-sm text-zinc-500">ผลงาน</div>
                <div class="text-2xl font-bold">{{ $summary['entries'] }} รายการ</div>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="text-sm text-zinc-500">งานรวม</div>
                <div class="text-2xl font-bold">{{ $summary['hours'] }} งาน</div>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="text-sm text-zinc-500">คะแนน</div>
                <div class="text-2xl font-bold text-purple-600 dark:text-purple-400">
                    @if ($score instanceof WorkRecordScoreSummary && ($score->hasMaximum() || $score->hasEarned()))
                        {{ number_format($score->earned, 2) }}
                    @else
                        —
                    @endif
                </div>
            </div>
        </div>

        <script type="application/json" id="staffDashChartPayload">@json($chartData)</script>
        <div class="mb-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-white p-6 md:col-span-2 xl:col-span-1 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg" class="mb-4">จำนวนงานตามกลุ่มภาระงาน</flux:heading>
                <div wire:ignore class="relative h-64">
                    <canvas id="staffDashRadarChart"></canvas>
                    @unless ($chartData['hasEntries'])
                        <div class="absolute inset-0 flex items-center justify-center text-sm text-zinc-400">
                            ยังไม่บันทึกงาน
                        </div>
                    @endunless
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg" class="mb-4">สัดส่วนงาน</flux:heading>
                <div wire:ignore class="relative h-64">
                    <canvas id="staffDashHoursChart"></canvas>
                    @unless ($chartData['hasEntries'])
                        <div class="absolute inset-0 flex items-center justify-center text-sm text-zinc-400">
                            ยังไม่บันทึกงาน
                        </div>
                    @endunless
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg" class="mb-4">ความคืบหน้าภาระงาน</flux:heading>
                <div wire:ignore class="relative h-64">
                    <canvas id="staffDashProgressChart"></canvas>
                    @unless ($chartData['hasEntries'])
                        <div class="absolute inset-0 flex items-center justify-center text-sm text-zinc-400">
                            ยังไม่บันทึกงาน
                        </div>
                    @endunless
                </div>
            </div>
        </div>

        <div>
            <div class="mb-4 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg" class="mb-4">รายการล่าสุด</flux:heading>

                @if ($this->latestEntries->isNotEmpty())
                    <ul class="space-y-2">
                        @foreach ($this->latestEntries as $entry)
                            @php
                                $workload = $entry->workload;
                                $main = $workload?->workloadMain;
                                $group = $main?->workloadGroup;
                            @endphp
                            <li
                                wire:key="latest-entry-{{ $entry->id }}"
                                class="rounded-lg border border-zinc-100 bg-zinc-50 px-3 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                            >
                                <span class="line-clamp-2 font-medium">{{ Str::limit($entry->detail, 200) }}</span>
                                @if ($workload)
                                    <p class="mt-1 text-xs text-zinc-500">
                                        {{ $group?->name }}
                                        @if ($main)
                                            <span class="mx-1">›</span>{{ $main->name }}
                                        @endif
                                        <span class="mx-1">›</span>{{ $workload->name }}
                                    </p>
                                @endif
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <flux:badge size="sm">{{ $entry->updated_at->format('d/m/Y') }}</flux:badge>
                                    @if (filled($entry->hours))
                                        <flux:badge size="sm">{{ $entry->hours }} งาน</flux:badge>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-zinc-400">ยังไม่มีผลงาน</p>
                @endif
            </div>
        </div>
    @else
        <flux:callout variant="secondary" icon="information-circle">
            ยังไม่มีบันทึกในรอบนี้
        </flux:callout>
    @endif

    @script
    <script>
        let staffDashRadarChart = null;
        let staffDashHoursChart = null;
        let staffDashProgressChart = null;

        const staffDashHoursColors = ['#60a5fa', '#34d399', '#fbbf24', '#fb7185', '#a78bfa', '#22d3ee'];

        function destroyStaffDashCharts() {
            [staffDashRadarChart, staffDashHoursChart, staffDashProgressChart].forEach((chart) => {
                if (chart) {
                    chart.destroy();
                }
            });
            staffDashRadarChart = null;
            staffDashHoursChart = null;
            staffDashProgressChart = null;
        }

        function renderStaffDashCharts() {
            const payloadEl = document.getElementById('staffDashChartPayload');

            if (! payloadEl) {
                destroyStaffDashCharts();

                return;
            }

            const data = JSON.parse(payloadEl.textContent);
            const Chart = window.Chart;

            if (! Chart) {
                return;
            }

            const radarCanvas = document.getElementById('staffDashRadarChart');
            const hoursCanvas = document.getElementById('staffDashHoursChart');
            const progressCanvas = document.getElementById('staffDashProgressChart');

            if (!radarCanvas || !hoursCanvas || !progressCanvas) {
                destroyStaffDashCharts();

                return;
            }

            destroyStaffDashCharts();

            const radar = data.hoursRadar ?? { labels: [], values: [] };

            staffDashRadarChart = new Chart(radarCanvas, {
                type: 'radar',
                data: {
                    labels: radar.labels,
                    datasets: [{
                        label: 'จำนวนงาน',
                        data: radar.values,
                        backgroundColor: 'rgba(168, 85, 247, 0.2)',
                        borderColor: '#a855f7',
                        pointBackgroundColor: '#a855f7',
                        borderWidth: 2,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => `${ctx.label}: ${ctx.parsed.r.toFixed(1)} งาน`,
                            },
                        },
                    },
                    scales: {
                        r: {
                            beginAtZero: true,
                            ticks: { precision: 0 },
                        },
                    },
                },
            });

            const hours = data.hoursByGroup ?? { labels: [], values: [] };

            staffDashHoursChart = new Chart(hoursCanvas, {
                type: 'doughnut',
                data: {
                    labels: hours.labels,
                    datasets: [{
                        data: hours.values,
                        backgroundColor: hours.values.map((_, i) => staffDashHoursColors[i % staffDashHoursColors.length]),
                        borderWidth: 0,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => `${ctx.label}: ${ctx.parsed.toFixed(1)} งาน`,
                            },
                        },
                    },
                },
            });

            const progress = data.progress ?? { filled: 0, empty: 0, percent: 0 };

            staffDashProgressChart = new Chart(progressCanvas, {
                type: 'doughnut',
                data: {
                    labels: ['บันทึกงานแล้ว', 'ยังไม่บันทึกงาน'],
                    datasets: [{
                        data: [progress.filled, progress.empty],
                        backgroundColor: ['#4ade80', '#e4e4e7'],
                        borderWidth: 0,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%',
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => `${ctx.label}: ${ctx.parsed} ภาระงาน`,
                            },
                        },
                    },
                },
            });
        }

        function scheduleStaffDashCharts() {
            setTimeout(() => renderStaffDashCharts(), 50);
        }

        scheduleStaffDashCharts();

        $wire.$watch('filterFiscalYearId', () => scheduleStaffDashCharts());
        $wire.$watch('filterPeriodId', () => scheduleStaffDashCharts());
    </script>
    @endscript
</div>
