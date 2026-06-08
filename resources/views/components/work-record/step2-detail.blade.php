@props([
    'readOnly' => false,
    'activeWorkload' => null,
    'selectedWorkloadId' => null,
    'entries' => [],
])

@php
    $active = $activeWorkload;
    $workloadId = $selectedWorkloadId;
    $workloadEntries = $workloadId ? ($entries[$workloadId] ?? []) : [];
@endphp

<div {{ $attributes->class(['rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800']) }} id="workload-detail-panel">
    @if ($active)
        <div class="border-b border-zinc-100 px-4 py-4 dark:border-zinc-700">
            <p class="mb-1 text-xs text-zinc-500">
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
                                <span class="line-clamp-2 font-medium">{{ Str::limit($entry['detail'], 80) }}</span>
                                @if (filled($entry['result'] ?? null))
                                    <span class="mt-1 block line-clamp-1 text-zinc-500">{{ Str::limit($entry['result'], 60) }}</span>
                                @endif
                                @if (filled($entry['hours'] ?? null))
                                    <span class="mt-1 block text-zinc-500">{{ $entry['hours'] }} งาน</span>
                                @endif
                            </button>
                            @unless ($readOnly)
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
