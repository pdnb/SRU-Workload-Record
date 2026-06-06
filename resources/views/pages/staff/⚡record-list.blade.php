<?php

use App\Enums\WorkRecordStatus;
use App\Models\WorkRecord;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function groupedRecords()
    {
        return Auth::user()
            ->workRecords()
            ->with(['evaluationPeriod.fiscalYear', 'workEntries.workload.workloadMain.workloadGroup'])
            ->latest('updated_at')
            ->get()
            ->groupBy(fn (WorkRecord $record) => $record->evaluationPeriod->fiscalYear->year);
    }
}; ?>

<div>
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">บันทึกของฉัน</flux:heading>
            <flux:subheading>รายการบันทึกภาระงานตามปีงบประมาณ</flux:subheading>
        </div>

        <flux:button :href="route('staff.records.create')" variant="primary" icon="plus" wire:navigate>
            สร้างบันทึกใหม่
        </flux:button>
    </div>

    @forelse ($this->groupedRecords as $year => $records)
        <div class="mb-8" wire:key="fy-{{ $year }}">
            <flux:heading size="lg" class="mb-4">ปีงบประมาณ {{ $year }}</flux:heading>

            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-900">
                        <tr>
                            <th class="px-4 py-3 text-left text-sm font-medium text-zinc-600 dark:text-zinc-400">รอบประเมิน</th>
                            <th class="px-4 py-3 text-left text-sm font-medium text-zinc-600 dark:text-zinc-400">สถานะ</th>
                            <th class="px-4 py-3 text-left text-sm font-medium text-zinc-600 dark:text-zinc-400">ผลงาน</th>
                            <th class="px-4 py-3 text-left text-sm font-medium text-zinc-600 dark:text-zinc-400">งาน</th>
                            <th class="px-4 py-3 text-left text-sm font-medium text-zinc-600 dark:text-zinc-400">คะแนน</th>
                            <th class="px-4 py-3 text-right text-sm font-medium text-zinc-600 dark:text-zinc-400">การดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($records as $record)
                            <tr wire:key="record-{{ $record->id }}">
                                <td class="px-4 py-3 text-sm">{{ $record->evaluationPeriod->label }}</td>
                                <td class="px-4 py-3">
                                    <flux:badge :color="$record->status->color()" size="sm">
                                        {{ $record->status->label() }}
                                    </flux:badge>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <flux:badge size="sm" color="blue">
                                        {{ $record->workEntries->count() }} รายการ
                                    </flux:badge>
                                </td>
                                <td class="px-4 py-3 text-sm">{{ number_format($record->workEntries->sum('hours'), 1) }}</td>
                                @php $score = $record->scoreSummary(); @endphp
                                <td class="px-4 py-3 text-sm">
                                    @if ($score->hasMaximum())
                                        <flux:badge size="sm" color="purple">
                                            {{ number_format($score->earned, 2) }}
                                        </flux:badge>
                                    @elseif ($score->hasEarned())
                                        <flux:badge size="sm" color="purple">
                                            {{ number_format($score->earned, 2) }}
                                        </flux:badge>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <flux:button
                                            :href="route('staff.records.show', $record)"
                                            variant="filled"
                                            size="sm"
                                            icon="eye"
                                            wire:navigate
                                        >
                                            ดู
                                        </flux:button>

                                        @if (in_array($record->status, [WorkRecordStatus::Draft, WorkRecordStatus::Rejected]))
                                            <flux:button
                                                :href="route('staff.records.edit', $record)"
                                                variant="filled"
                                                size="sm"
                                                icon="pencil-square"
                                                wire:navigate
                                            >
                                                แก้ไข
                                            </flux:button>
                                        @endif

                                        <flux:button
                                            :href="route('staff.records.print', $record)"
                                            variant="filled"
                                            size="sm"
                                            icon="printer"
                                            target="_blank"
                                        >
                                            พิมพ์
                                        </flux:button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <x-empty-state icon="clipboard-document-list" message="ยังไม่มีบันทึกภาระงาน">
            <flux:button :href="route('staff.records.create')" variant="primary" wire:navigate>
                สร้างบันทึกแรก
            </flux:button>
        </x-empty-state>
    @endforelse
</div>
