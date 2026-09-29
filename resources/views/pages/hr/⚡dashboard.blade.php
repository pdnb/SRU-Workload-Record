<?php

use App\Enums\WorkRecordStatus;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\WorkRecord;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?int $filterFiscalYearId = null;

    public ?int $filterPeriodId = null;

    public ?string $filterDepartment = null;

    public function mount(): void
    {
        $this->filterFiscalYearId = FiscalYear::query()->active()->value('id')
            ?? FiscalYear::query()->orderByDesc('year')->value('id');
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
    public function departments()
    {
        return User::query()
            ->whereNotNull('department')
            ->distinct()
            ->orderBy('department')
            ->pluck('department');
    }

    #[Computed]
    public function filteredRecords()
    {
        return WorkRecord::query()
            ->with(['user', 'evaluationPeriod.fiscalYear', 'workEntries'])
            ->when($this->filterPeriodId, fn ($q) => $q->where('evaluation_period_id', $this->filterPeriodId))
            ->when($this->filterFiscalYearId && ! $this->filterPeriodId, fn ($q) => $q->whereHas(
                'evaluationPeriod',
                fn ($p) => $p->where('fiscal_year_id', $this->filterFiscalYearId),
            ))
            ->when($this->filterDepartment, fn ($q) => $q->whereHas(
                'user',
                fn ($u) => $u->where('department', $this->filterDepartment),
            ))
            ->get();
    }

    #[Computed]
    public function stats(): array
    {
        $records = $this->filteredRecords;

        return [
            'total' => $records->count(),
            'draft' => $records->where('status', WorkRecordStatus::Draft)->count(),
            'submitted' => $records->where('status', WorkRecordStatus::Submitted)->count(),
            'approved' => $records->where('status', WorkRecordStatus::Approved)->count(),
            'rejected' => $records->where('status', WorkRecordStatus::Rejected)->count(),
            'hours' => $records->sum(fn ($r) => $r->workEntries->sum('hours')),
        ];
    }

    #[Computed]
    public function chartData(): array
    {
        $stats = $this->stats;

        return [
            'labels' => ['ฉบับร่าง', 'ส่งแล้ว', 'อนุมัติแล้ว', 'ส่งกลับแก้ไข'],
            'values' => [$stats['draft'], $stats['submitted'], $stats['approved'], $stats['rejected']],
        ];
    }

    public function updatedFilterFiscalYearId(): void
    {
        $this->filterPeriodId = null;
        unset($this->periods, $this->filteredRecords, $this->stats, $this->chartData);
    }

    public function updatedFilterPeriodId(): void
    {
        unset($this->filteredRecords, $this->stats, $this->chartData);
    }

    public function updatedFilterDepartment(): void
    {
        unset($this->filteredRecords, $this->stats, $this->chartData);
    }

    public function exportUrl(): string
    {
        return route('hr.dashboard.export', array_filter([
            'fiscal_year' => $this->filterFiscalYearId,
            'period' => $this->filterPeriodId,
            'department' => $this->filterDepartment,
        ]));
    }
}; ?>

<div>
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">แดชบอร์ด</flux:heading>
            <flux:subheading>ภาพรวมบันทึกภาระงานทั้งหมด</flux:subheading>
        </div>

        <flux:button :href="$this->exportUrl()" variant="primary" icon="arrow-down-tray">
            ส่งออก Excel
        </flux:button>
    </div>

    <div class="mb-8 grid gap-4 sm:grid-cols-3">
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
                <option value="">ทั้งหมด</option>
                @foreach ($this->periods as $period)
                    <option wire:key="period-opt-{{ $period->id }}" value="{{ $period->id }}">{{ $period->label }}</option>
                @endforeach
            </x-searchable-select>
        </flux:field>

        <flux:field>
            <flux:label>หน่วยงาน</flux:label>
            <x-searchable-select wire:model.live="filterDepartment">
                <option value="">ทั้งหมด</option>
                @foreach ($this->departments as $department)
                    <option wire:key="dept-opt-{{ $loop->index }}" value="{{ $department }}">{{ $department }}</option>
                @endforeach
            </x-searchable-select>
        </flux:field>
    </div>

    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-sm text-zinc-500">ทั้งหมด</div>
            <div class="text-2xl font-bold">{{ $this->stats['total'] }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-sm text-zinc-500">ฉบับร่าง</div>
            <div class="text-2xl font-bold text-zinc-600">{{ $this->stats['draft'] }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-sm text-zinc-500">ส่งแล้ว</div>
            <div class="text-2xl font-bold text-blue-600">{{ $this->stats['submitted'] }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-sm text-zinc-500">อนุมัติแล้ว</div>
            <div class="text-2xl font-bold text-green-600">{{ $this->stats['approved'] }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-sm text-zinc-500">งานรวม</div>
            <div class="text-2xl font-bold">{{ number_format($this->stats['hours'], 1) }}</div>
        </div>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:heading size="lg" class="mb-4">บันทึกตามสถานะ</flux:heading>
        <div wire:ignore class="relative h-72">
            <canvas id="statusChart"></canvas>
        </div>
    </div>

    @script
    <script>
        let statusChart = null;

        function renderStatusChart() {
            const data = $wire.chartData;
            const Chart = window.Chart;

            if (! Chart) {
                return;
            }

            const canvas = document.getElementById('statusChart');
            if (!canvas) return;

            if (statusChart) {
                statusChart.destroy();
            }

            statusChart = new Chart(canvas, {
                    type: 'bar',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'จำนวนบันทึก',
                            data: data.values,
                            backgroundColor: ['#a1a1aa', '#60a5fa', '#4ade80', '#f87171'],
                            borderRadius: 6,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1 } },
                        },
                    },
                });
        }

        renderStatusChart();

        $wire.$watch('filterFiscalYearId', () => renderStatusChart());
        $wire.$watch('filterPeriodId', () => renderStatusChart());
        $wire.$watch('filterDepartment', () => renderStatusChart());
    </script>
    @endscript
</div>
