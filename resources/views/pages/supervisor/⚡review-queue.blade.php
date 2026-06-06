<?php

use App\Enums\WorkRecordStatus;
use App\Models\WorkRecord;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?int $expandedRecordId = null;

    public ?int $rejectingRecordId = null;

    public bool $rejectModalOpen = false;

    public string $rejectionComment = '';

    #[Computed]
    public function pendingRecords()
    {
        return WorkRecord::query()
            ->with([
                'user',
                'evaluationPeriod.fiscalYear',
                'workEntries.workload.workloadMain.workloadGroup',
            ])
            ->where('status', WorkRecordStatus::Submitted)
            ->whereHas('user', fn ($q) => $q->where('department', Auth::user()->department))
            ->latest('submitted_at')
            ->get();
    }

    public function toggleExpand(int $recordId): void
    {
        $this->expandedRecordId = $this->expandedRecordId === $recordId ? null : $recordId;
    }

    public function approve(int $recordId): void
    {
        $record = WorkRecord::findOrFail($recordId);
        Gate::authorize('approve', $record);

        $record->update([
            'status' => WorkRecordStatus::Approved,
            'approved_at' => now(),
            'rejection_comment' => null,
        ]);

        unset($this->pendingRecords);
        Flux::toast('อนุมัติบันทึกเรียบร้อยแล้ว');
    }

    public function openReject(int $recordId): void
    {
        $this->rejectingRecordId = $recordId;
        $this->rejectionComment = '';
        $this->rejectModalOpen = true;
    }

    public function cancelReject(): void
    {
        $this->rejectingRecordId = null;
        $this->rejectionComment = '';
        $this->rejectModalOpen = false;
    }

    public function reject(): void
    {
        $this->validate([
            'rejectionComment' => ['required', 'string', 'min:5'],
        ]);

        $record = WorkRecord::findOrFail($this->rejectingRecordId);
        Gate::authorize('reject', $record);

        $record->update([
            'status' => WorkRecordStatus::Rejected,
            'rejection_comment' => $this->rejectionComment,
            'approved_at' => null,
        ]);

        $this->cancelReject();
        unset($this->pendingRecords);
        Flux::toast('ส่งกลับเพื่อแก้ไขแล้ว');
    }
}; ?>

<div>
    <div class="mb-8">
        <flux:heading size="xl">ตรวจสอบผลงาน</flux:heading>
        <flux:subheading>บันทึกที่รอการอนุมัติในหน่วยงาน {{ auth()->user()->department }}</flux:subheading>
    </div>

    @forelse ($this->pendingRecords as $record)
        <div wire:key="review-{{ $record->id }}" class="mb-4 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="font-semibold">{{ $record->user->name }}</div>
                    <div class="text-sm text-zinc-500">{{ $record->evaluationPeriod->label }}</div>
                    <div class="mt-1 flex flex-wrap gap-2 text-sm text-zinc-500">
                        <span>{{ $record->workEntries->count() }} ผลงาน</span>
                        <span>·</span>
                        <span>{{ number_format($record->workEntries->sum('hours'), 1) }} ชม.</span>
                        @if ($record->submitted_at)
                            <span>·</span>
                            <span>ส่ง {{ $record->submitted_at->format('d/m/Y H:i') }}</span>
                        @endif
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <flux:button wire:click="toggleExpand({{ $record->id }})" variant="ghost" size="sm">
                        {{ $expandedRecordId === $record->id ? 'ซ่อนรายละเอียด' : 'ดูรายละเอียด' }}
                    </flux:button>
                    <flux:button
                        wire:click="approve({{ $record->id }})"
                        variant="primary"
                        size="sm"
                        wire:loading.attr="disabled"
                        wire:target="approve({{ $record->id }})"
                    >
                        อนุมัติ
                    </flux:button>
                    <flux:button wire:click="openReject({{ $record->id }})" variant="danger" size="sm">
                        ส่งกลับ
                    </flux:button>
                </div>
            </div>

            @if ($expandedRecordId === $record->id)
                <div class="border-t border-zinc-100 p-4 dark:border-zinc-700">
                    @php
                        $grouped = $record->workEntries->groupBy(fn ($e) => $e->workload->name);
                    @endphp

                    @foreach ($grouped as $workloadName => $entries)
                        <div wire:key="wl-{{ $record->id }}-{{ $loop->index }}" class="mb-4">
                            <div class="mb-2 font-medium text-zinc-700 dark:text-zinc-300">{{ $workloadName }}</div>
                            <div class="space-y-3">
                                @foreach ($entries as $entry)
                                    <div class="rounded-lg bg-zinc-50 p-4 text-sm dark:bg-zinc-900">
                                        <p class="whitespace-pre-wrap">{{ $entry->detail }}</p>
                                        @if ($entry->result)
                                            <p class="mt-2 text-zinc-600 dark:text-zinc-400"><strong>ผลลัพธ์:</strong> {{ $entry->result }}</p>
                                        @endif
                                        @if ($entry->hours)
                                            <p class="mt-1 text-zinc-500">{{ $entry->hours }} งาน</p>
                                        @endif
                                        @if ($entry->hasEvidence())
                                            <div class="mt-2 space-y-1">
                                                @if ($entry->evidence_type?->value === 'file')
                                                    @foreach ($entry->evidenceFilePaths() as $fileIndex => $path)
                                                        <div>
                                                            <flux:link
                                                                :href="route('staff.evidence.download', ['entry' => $entry, 'fileIndex' => $fileIndex])"
                                                                target="_blank"
                                                            >
                                                                ดาวน์โหลด {{ basename($path) }}
                                                            </flux:link>
                                                        </div>
                                                    @endforeach
                                                @else
                                                    <flux:link :href="$entry->evidence" target="_blank">เปิดลิงก์หลักฐาน</flux:link>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    @if ($record->self_assessment)
                        <div class="mt-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                            <div class="mb-1 text-sm font-medium">การประเมินตนเอง</div>
                            <p class="whitespace-pre-wrap text-sm text-zinc-600 dark:text-zinc-400">{{ $record->self_assessment }}</p>
                            @if ($record->self_score)
                                <div class="mt-2">
                                    <x-star-rating :score="$record->self_score" readonly size="sm" />
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @empty
        <x-empty-state icon="check-circle" message="ไม่มีบันทึกที่รอตรวจสอบ" />
    @endforelse

    <flux:modal wire:model="rejectModalOpen" class="md:w-md">
        <div class="space-y-4">
            <flux:heading size="lg">ส่งกลับเพื่อแก้ไข</flux:heading>
            <flux:field>
                <flux:label>เหตุผล / ข้อเสนอแนะ <span class="text-red-500">*</span></flux:label>
                <flux:textarea wire:model="rejectionComment" rows="4" placeholder="ระบุเหตุผลที่ส่งกลับ..." />
                <flux:error name="rejectionComment" />
            </flux:field>
            <div class="flex justify-end gap-2">
                <flux:button wire:click="cancelReject" variant="ghost">ยกเลิก</flux:button>
                <flux:button wire:click="reject" variant="danger" wire:loading.attr="disabled">ส่งกลับ</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
