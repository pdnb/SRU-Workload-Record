@props([
    'readOnly' => false,
    'workloadFilter' => 'all',
    'selectedWorkloadId' => null,
    'filteredWorkloadTree' => null,
])

<div
    {{ $attributes->class(['flex flex-col rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800']) }}
    x-data="{
        openGroups: {},
        openMains: {},
        toggleGroup(id) { this.openGroups[id] = !this.openGroups[id]; },
        toggleMain(id) { this.openMains[id] = !this.openMains[id]; },
        isGroupOpen(id) { return this.openGroups[id] === true; },
        isMainOpen(id) { return this.openMains[id] === true; },
    }"
>
    <div class="shrink-0 space-y-3 border-b border-zinc-100 p-4 dark:border-zinc-700">
        <flux:field>
            <flux:label class="sr-only">ค้นหาภาระงาน</flux:label>
            <flux:input
                wire:model.live.debounce.300ms="workloadSearch"
                icon="magnifying-glass"
                placeholder="ค้นหาชื่อภาระงาน..."
                clearable
            />
        </flux:field>

        <div class="flex flex-wrap gap-1">
            <flux:button
                wire:click="setWorkloadFilter('all')"
                size="xs"
                variant="{{ $workloadFilter === 'all' ? 'primary' : 'ghost' }}"
            >
                ทั้งหมด
            </flux:button>
            <flux:button
                wire:click="setWorkloadFilter('filled')"
                size="xs"
                variant="{{ $workloadFilter === 'filled' ? 'primary' : 'ghost' }}"
            >
                มีผลงานแล้ว
            </flux:button>
            <flux:button
                wire:click="setWorkloadFilter('empty')"
                size="xs"
                variant="{{ $workloadFilter === 'empty' ? 'primary' : 'ghost' }}"
            >
                ยังไม่มีผลงาน
            </flux:button>
        </div>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto p-2">
        @forelse ($filteredWorkloadTree as $branch)
            @php
                $group = $branch['group'];
            @endphp
            <div wire:key="sidebar-group-{{ $group->id }}" class="mb-1">
                <button
                    type="button"
                    x-on:click="toggleGroup({{ $group->id }})"
                    class="flex w-full cursor-pointer items-center gap-2 rounded-lg px-2 py-2 text-left text-sm font-medium hover:bg-zinc-50 dark:hover:bg-zinc-900"
                >
                    <span x-bind:class="isGroupOpen({{ $group->id }}) ? 'rotate-90' : ''" class="text-zinc-400 transition-transform">›</span>
                    <span class="min-w-0 flex-1 truncate">{{ $group->name }}</span>
                </button>

                <div x-show="isGroupOpen({{ $group->id }})" x-cloak class="pl-2">
                    @foreach ($branch['mains'] as $mainBranch)
                        @php
                            $main = $mainBranch['main'];
                        @endphp
                        <div wire:key="sidebar-main-{{ $main->id }}" class="mb-1">
                            <button
                                type="button"
                                x-on:click="toggleMain({{ $main->id }})"
                                class="flex w-full cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs font-medium text-zinc-500 hover:bg-zinc-50 dark:hover:bg-zinc-900"
                            >
                                <span x-bind:class="isMainOpen({{ $main->id }}) ? 'rotate-90' : ''" class="text-zinc-400 transition-transform">›</span>
                                <span class="min-w-0 flex-1 truncate">{{ $main->name }}</span>
                            </button>

                            <div x-show="isMainOpen({{ $main->id }})" x-cloak class="space-y-0.5 pl-3">
                                @foreach ($mainBranch['workloads'] as $item)
                                    <button
                                        type="button"
                                        wire:key="sidebar-wl-{{ $item['id'] }}"
                                        wire:click="selectWorkload({{ $item['id'] }})"
                                        @class([
                                            'cursor-pointer flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm transition',
                                            'bg-blue-50 text-blue-700 ring-1 ring-blue-200 dark:bg-blue-950 dark:text-blue-300 dark:ring-blue-800' => $selectedWorkloadId === $item['id'],
                                            'hover:bg-zinc-50 dark:hover:bg-zinc-900' => $selectedWorkloadId !== $item['id'],
                                        ])
                                    >
                                        <span class="min-w-0 flex-1 truncate">{{ $item['name'] }}</span>
                                        @if ($item['entry_count'] > 0)
                                            <flux:badge size="sm" color="blue">{{ $item['entry_count'] }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">0</flux:badge>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <x-empty-state icon="magnifying-glass" message="ไม่พบภาระงานที่ตรงกับการค้นหา" class="py-8" />
        @endforelse
    </div>
</div>
