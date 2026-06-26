@props([
    'readOnly' => false,
    'activeWorkload' => null,
    'selectedWorkloadId' => null,
    'entries' => [],
    'canShare' => false,
    'shareEnabled' => false,
    'shareUrl' => null,
])

@php
    $active = $activeWorkload;
    $workloadId = $selectedWorkloadId;
    $workloadEntries = $workloadId ? ($entries[$workloadId] ?? []) : [];
@endphp

<div {{ $attributes->class(['rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800']) }} id="workload-detail-panel">
    @if ($active)
        <div class="border-b border-zinc-100 px-4 py-4 dark:border-zinc-700">
            <p class="mb-1 text-sm text-zinc-500">
                <span class="tabular-nums">{{ $active['group_number'] ?? '' }}</span>
                <span class="mx-1">›</span>
                <span class="tabular-nums">{{ $active['main_number'] ?? '' }}</span>
                <span class="mx-1">›</span>
                <span class="tabular-nums">{{ $active['number'] ?? '' }}</span>
            </p>
            <flux:heading size="lg">{{ $active['name'] }}</flux:heading>
            <div class="mt-2 flex flex-wrap gap-2">
                <flux:badge size="sm" color="blue">{{ count($workloadEntries) }} ผลงาน</flux:badge>
                @if (filled($active['score'] ?? null))
                    <flux:badge size="sm" color="zinc">หน่วยละ {{ number_format($active['score'], 2) }} คะแนน</flux:badge>
                @endif
                @if (($active['earned_score'] ?? 0) > 0)
                    <flux:badge size="sm" color="purple">ได้
                        @if(filled($active['main_max_score'] ?? null) && $active['earned_score'] > $active['main_max_score'])
                            {{ number_format($active['main_max_score'], 2) }}
                        @else
                            {{ number_format($active['earned_score'], 2) }}
                        @endif
                        คะแนน
                    </flux:badge>
                @endif
                @if (filled($active['main_max_score'] ?? null))
                    <flux:badge size="sm" color="zinc">สูงสุด {{ number_format($active['main_max_score'], 2) }} คะแนน</flux:badge>
                @endif
                @if ($active['hours_sum'] > 0)
                    <flux:badge size="sm" color="zinc">{{ number_format($active['hours_sum'], 1) }} งาน</flux:badge>
                @endif
            </div>

            @if ($canShare && count($workloadEntries))
                <div class="mt-4 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-600 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="text-sm font-medium">แชร์ลิงก์สรุปผลงาน</p>
                            <p class="text-xs text-zinc-500">
                                @if ($shareEnabled)
                                    ลิงก์เปิดใช้งาน — ผู้รับไม่ต้องล็อกอิน
                                @else
                                    สร้างลิงก์สาธารณะสรุปผลงานภาระงานนี้
                                @endif
                            </p>
                        </div>
                        @if ($shareEnabled)
                            <flux:badge size="sm" color="green">เปิดใช้งาน</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">ปิดอยู่</flux:badge>
                        @endif
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        @if ($shareEnabled && $shareUrl)
                            <flux:button
                                type="button"
                                variant="filled"
                                size="sm"
                                icon="link"
                                x-on:click="navigator.clipboard.writeText(@js($shareUrl)); $wire.notifyShareLinkCopied()"
                            >
                                คัดลอกลิงก์
                            </flux:button>
                            <flux:button
                                :href="$shareUrl"
                                variant="ghost"
                                size="sm"
                                icon="arrow-top-right-on-square"
                                target="_blank"
                            >
                                ดูตัวอย่าง
                            </flux:button>
                            <flux:button
                                wire:click="disableWorkloadShare"
                                variant="ghost"
                                size="sm"
                                icon="x-mark"
                                wire:loading.attr="disabled"
                            >
                                ปิดการแชร์
                            </flux:button>
                        @else
                            <flux:button
                                wire:click="enableWorkloadShare"
                                variant="primary"
                                size="sm"
                                icon="share"
                                wire:loading.attr="disabled"
                            >
                                เปิดการแชร์
                            </flux:button>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <div class="p-4">
            @if (count($workloadEntries))
                <ul class="space-y-2">
                    @foreach ($workloadEntries as $index => $entry)
                        @php
                            $unitScore = $active['score'] ?? null;
                            $entryEarned = filled($unitScore) && filled($entry['hours'] ?? null)
                                ? (float) $unitScore * (float) $entry['hours']
                                : null;
                            $isMirror = ! empty($entry['is_mirror']);
                        @endphp
                        <li
                            wire:key="entry-{{ $workloadId }}-{{ $index }}"
                            class="flex items-center justify-between gap-2 rounded-lg border border-zinc-100 bg-zinc-50 px-3 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                        >
                            <button
                                type="button"
                                wire:click="openEntryModal({{ $workloadId }}, {{ $index }})"
                                class="min-w-0 flex-1 cursor-pointer text-left hover:text-blue-600"
                            >
                                <span class="line-clamp-2 font-medium">{{ Str::limit($entry['detail'], 200) }}</span>
                                @if ($isMirror)
                                    <div class="mt-1">
                                        <flux:badge size="sm" color="zinc">
                                            ผลงานร่วมจาก {{ $entry['shared_from_name'] ?? 'บุคลากร' }}
                                        </flux:badge>
                                    </div>
                                @endif
                                @if (filled($entry['recorded_at'] ?? null) || filled($entry['result'] ?? null) || filled($entry['hours'] ?? null))
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @if (filled($entry['recorded_at'] ?? null))
                                            @php
                                                $recordedAt = \Illuminate\Support\Carbon::parse($entry['recorded_at']);
                                            @endphp
                                            <flux:badge size="sm">
                                                {{ $recordedAt->format('d/m/Y') }}
                                            </flux:badge>
                                        @endif
                                        @if (filled($entry['hours'] ?? null))
                                            <flux:badge size="sm">{{ $entry['hours'] }} งาน</flux:badge>
                                        @endif
                                    </div>
                                @endif

                                @if (filled($entry['result'] ?? null))
                                    <span class="mt-1 block line-clamp-1 text-zinc-500">{{ Str::limit($entry['result'], 60) }}</span>
                                @endif

                                @if (! $isMirror && filled($entry['collaborator_names'] ?? []))
                                    <span class="mt-1 block text-xs text-zinc-500">
                                        ผู้ร่วมงาน: {{ implode(', ', $entry['collaborator_names']) }}
                                    </span>
                                @endif
                            </button>
                            @unless ($readOnly || $isMirror)
                                <div class="flex shrink-0 gap-1">
                                    <flux:button wire:click="openEntryModal({{ $workloadId }}, {{ $index }})" variant="ghost" size="xs" icon="pencil-square" />
                                </div>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            @else
                <x-empty-state icon="document-text" message="ยังไม่มีผลงานในภาระงานนี้" class="py-8">
                    @unless ($readOnly)
                        <flux:button wire:click="openEntryModal({{ $workloadId }})" variant="primary" size="sm" icon="plus">
                            เพิ่มผลงานแรก
                        </flux:button>
                    @endunless
                </x-empty-state>
            @endif

            @if (count($workloadEntries) && ! $readOnly)
                <div class="mt-4">
                    <flux:button wire:click="openEntryModal({{ $workloadId }})" variant="primary" size="sm" icon="plus">
                        เพิ่มผลงาน
                    </flux:button>
                </div>
            @endif
        </div>
    @else
        <x-empty-state icon="arrow-left" message="เลือกภาระงานจากรายการด้านซ้ายเพื่อบันทึกผลงาน" class="py-16" />
    @endif
</div>
