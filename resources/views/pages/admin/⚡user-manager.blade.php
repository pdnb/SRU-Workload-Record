<?php

use App\Actions\Oracle\SyncStaffPositions;
use App\Enums\PositionSource;
use App\Enums\UserRole;
use App\Models\Position;
use App\Models\User;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    /** '' = all, 'none' = without position, otherwise a position id */
    #[Url(as: 'position')]
    public string $positionFilter = '';

    public bool $showPositionModal = false;

    public ?int $editingPositionId = null;

    public string $positionCode = '';

    public string $positionName = '';

    public bool $positionIsActive = true;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPositionFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function users()
    {
        $term = trim($this->search);

        return User::query()
            ->with('assignedPosition')
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('external_id', 'like', "%{$term}%")))
            ->when($this->positionFilter === 'none', fn ($q) => $q->whereNull('position_id'))
            ->when(ctype_digit($this->positionFilter), fn ($q) => $q->where('position_id', (int) $this->positionFilter))
            ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [UserRole::Staff->value])
            ->orderBy('name')
            ->paginate(25);
    }

    #[Computed]
    public function positions()
    {
        return Position::query()
            ->withCount('users')
            ->orderByDesc('is_active')
            ->orderBy('code')
            ->get();
    }

    #[Computed]
    public function staffWithoutPositionCount(): int
    {
        return User::where('role', UserRole::Staff)->whereNull('position_id')->count();
    }

    public function setUserPosition(int $userId, string $positionId): void
    {
        $user = User::findOrFail($userId);

        $positionId = $positionId === '' ? null : (int) $positionId;
        abort_if($positionId !== null && ! Position::whereKey($positionId)->exists(), 422);

        $user->update([
            'position_id' => $positionId,
            'position_source' => PositionSource::Manual,
        ]);

        unset($this->users, $this->positions, $this->staffWithoutPositionCount);
        Flux::toast("กำหนดตำแหน่งให้ {$user->name} แล้ว (กำหนดเอง)");
    }

    public function useOraclePosition(int $userId): void
    {
        $user = User::findOrFail($userId);
        $user->update(['position_source' => PositionSource::Oracle]);

        if (config('oracle_workload.enabled')) {
            try {
                $found = app(SyncStaffPositions::class)->forUser($user);
            } catch (\Throwable $e) {
                Flux::toast('ดึงตำแหน่งจาก PCM ไม่สำเร็จ: '.$e->getMessage(), variant: 'danger');

                return;
            }

            $found
                ? Flux::toast("อัปเดตตำแหน่งของ {$user->name} จาก PCM แล้ว")
                : Flux::toast("ไม่พบข้อมูลตำแหน่งของ {$user->name} ใน PCM", variant: 'warning');
        } else {
            Flux::toast('จะใช้ตำแหน่งจาก PCM ในการซิงก์ครั้งถัดไป');
        }

        unset($this->users, $this->positions, $this->staffWithoutPositionCount);
    }

    public function openCreatePosition(): void
    {
        $this->resetPositionForm();
        $this->showPositionModal = true;
    }

    public function openEditPosition(int $id): void
    {
        $position = Position::findOrFail($id);
        abort_if($position->isFromOracle(), 403);

        $this->editingPositionId = $id;
        $this->positionCode = $position->code;
        $this->positionName = $position->name;
        $this->positionIsActive = $position->is_active;
        $this->showPositionModal = true;
    }

    public function savePosition(): void
    {
        $this->validate([
            'positionCode' => ['required', 'string', 'max:50', Rule::unique('positions', 'code')->ignore($this->editingPositionId)],
            'positionName' => ['required', 'string', 'max:255'],
            'positionIsActive' => ['boolean'],
        ], [], [
            'positionCode' => 'รหัสตำแหน่ง',
            'positionName' => 'ชื่อตำแหน่ง',
        ]);

        $data = [
            'code' => trim($this->positionCode),
            'name' => trim($this->positionName),
            'is_active' => $this->positionIsActive,
        ];

        if ($this->editingPositionId) {
            $position = Position::findOrFail($this->editingPositionId);
            abort_if($position->isFromOracle(), 403);
            $position->update($data);
            Flux::toast('อัปเดตตำแหน่งแล้ว');
        } else {
            Position::create($data);
            Flux::toast('เพิ่มตำแหน่งแล้ว');
        }

        $this->showPositionModal = false;
        unset($this->positions);
    }

    public function deletePosition(int $id): void
    {
        $position = Position::withCount(['users', 'structures'])->findOrFail($id);
        abort_if($position->isFromOracle(), 403);

        if ($position->users_count > 0 || $position->structures_count > 0) {
            Flux::toast('ลบไม่ได้ — ตำแหน่งนี้มีผู้ใช้หรือโครงสร้างภาระงานอ้างอิงอยู่ (ปิดการใช้งานแทน)', variant: 'warning');

            return;
        }

        $position->delete();
        unset($this->positions);
        Flux::toast('ลบตำแหน่งแล้ว');
    }

    protected function resetPositionForm(): void
    {
        $this->resetErrorBag();
        $this->editingPositionId = null;
        $this->positionCode = '';
        $this->positionName = '';
        $this->positionIsActive = true;
    }
}; ?>

<div>
    <div class="mb-8">
        <flux:heading size="xl">ผู้ใช้และตำแหน่ง</flux:heading>
        <flux:subheading>กำหนดตำแหน่งของบุคลากร เพื่อเลือกโครงสร้างภาระงานที่ใช้บันทึก</flux:subheading>
    </div>

    @if ($this->staffWithoutPositionCount > 0)
        <flux:callout class="mb-6" variant="warning" icon="exclamation-triangle">
            มีบุคลากร {{ $this->staffWithoutPositionCount }} คนที่ยังไม่มีตำแหน่ง — จะยังสร้างบันทึกภาระงานไม่ได้
            <x-slot name="actions">
                <flux:button size="sm" wire:click="$set('positionFilter', 'none')">แสดงเฉพาะกลุ่มนี้</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end">
        <flux:field class="w-full sm:max-w-xs">
            <flux:label>ค้นหา</flux:label>
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="ชื่อ อีเมล หรือรหัสบุคลากร" />
        </flux:field>
        <flux:field class="w-full sm:max-w-xs">
            <flux:label>ตำแหน่ง</flux:label>
            <x-searchable-select wire:model.live="positionFilter">
                <option value="">ทั้งหมด</option>
                <option value="none">ยังไม่มีตำแหน่ง</option>
                @foreach ($this->positions as $position)
                    <option wire:key="filter-pos-{{ $position->id }}" value="{{ $position->id }}">{{ $position->label() }}</option>
                @endforeach
            </x-searchable-select>
        </flux:field>
    </div>

    <div class="mb-10 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <table class="w-full divide-y divide-zinc-200 dark:divide-zinc-700">
            <thead class="hidden bg-zinc-50 lg:table-header-group dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left text-sm font-medium text-zinc-600 dark:text-zinc-400">ชื่อ</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-zinc-600 dark:text-zinc-400">บทบาท</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-zinc-600 dark:text-zinc-400">ตำแหน่ง</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-zinc-600 dark:text-zinc-400">ที่มา</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->users as $user)
                    <tr class="block p-4 lg:table-row lg:p-0" wire:key="user-{{ $user->id }}">
                        <td class="block py-1 lg:table-cell lg:px-4 lg:py-3">
                            <div class="text-sm font-medium">{{ $user->name }}</div>
                            <div class="break-all text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $user->email }}@if ($user->external_id) · {{ $user->external_id }}@endif
                            </div>
                        </td>
                        <td class="flex items-center justify-between gap-4 py-1 lg:table-cell lg:px-4 lg:py-3">
                            <span class="text-xs font-medium text-zinc-500 lg:hidden dark:text-zinc-400">บทบาท</span>
                            <flux:badge size="sm" color="zinc">{{ $user->role->label() }}</flux:badge>
                        </td>
                        <td class="block py-1 lg:table-cell lg:px-4 lg:py-3">
                            <x-searchable-select
                                size="sm"
                                wire:change="setUserPosition({{ $user->id }}, $event.target.value)"
                                aria-label="ตำแหน่งของ {{ $user->name }}"
                            >
                                <option value="" @selected($user->position_id === null)>— ไม่มีตำแหน่ง —</option>
                                @foreach ($this->positions as $position)
                                    <option
                                        wire:key="user-{{ $user->id }}-pos-{{ $position->id }}"
                                        value="{{ $position->id }}"
                                        @selected($user->position_id === $position->id)
                                    >
                                        {{ $position->label() }}@unless ($position->is_active) (ไม่ใช้งาน)@endunless
                                    </option>
                                @endforeach
                            </x-searchable-select>
                        </td>
                        <td class="flex items-center justify-between gap-2 py-1 lg:table-cell lg:px-4 lg:py-3">
                            <span class="text-xs font-medium text-zinc-500 lg:hidden dark:text-zinc-400">ที่มา</span>
                            <div class="flex items-center gap-2">
                                @if ($user->position_source === PositionSource::Manual)
                                    <flux:badge size="sm" color="amber">{{ PositionSource::Manual->label() }}</flux:badge>
                                    <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="useOraclePosition({{ $user->id }})">
                                        ใช้ค่าจาก PCM
                                    </flux:button>
                                @else
                                    <flux:badge size="sm" color="sky">{{ PositionSource::Oracle->label() }}</flux:badge>
                                    @if ($user->position_synced_at)
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $user->position_synced_at->format('d/m/Y') }}</span>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-sm text-zinc-500">ไม่พบผู้ใช้</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="-mt-6 mb-10">
        {{ $this->users->links() }}
    </div>

    <div class="mb-4 flex items-center justify-between gap-4">
        <div>
            <flux:heading size="lg">ตำแหน่ง</flux:heading>
            <flux:subheading>ตำแหน่งจาก PCM แก้ไขได้ที่ระบบต้นทางเท่านั้น</flux:subheading>
        </div>
        <flux:button wire:click="openCreatePosition" variant="primary" icon="plus" size="sm">เพิ่มตำแหน่ง</flux:button>
    </div>

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($this->positions as $position)
                <li wire:key="pos-{{ $position->id }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                        <span class="shrink-0 tabular-nums text-sm text-zinc-500">{{ $position->code }}</span>
                        <span class="text-sm font-medium">{{ $position->name }}</span>
                        @if ($position->isFromOracle())
                            <flux:badge size="sm" color="sky">PCM</flux:badge>
                        @endif
                        @unless ($position->is_active)
                            <flux:badge size="sm" color="zinc">ไม่ใช้งาน</flux:badge>
                        @endunless
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $position->users_count }} คน</span>
                    </div>
                    @unless ($position->isFromOracle())
                        <div class="flex gap-1">
                            <flux:button wire:click="openEditPosition({{ $position->id }})" variant="ghost" size="xs" icon="pencil-square" />
                            <flux:button wire:click="deletePosition({{ $position->id }})" variant="ghost" size="xs" icon="trash" wire:confirm="ลบตำแหน่งนี้?" />
                        </div>
                    @endunless
                </li>
            @empty
                <li class="px-4 py-8 text-center text-sm text-zinc-500">ยังไม่มีตำแหน่ง</li>
            @endforelse
        </ul>
    </div>

    <flux:modal wire:model="showPositionModal" class="md:w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $editingPositionId ? 'แก้ไขตำแหน่ง' : 'เพิ่มตำแหน่ง' }}</flux:heading>
            <flux:field>
                <flux:label>รหัสตำแหน่ง</flux:label>
                <flux:input wire:model="positionCode" placeholder="เช่น 0901" />
                <flux:error name="positionCode" />
            </flux:field>
            <flux:field>
                <flux:label>ชื่อตำแหน่ง</flux:label>
                <flux:input wire:model="positionName" />
                <flux:error name="positionName" />
            </flux:field>
            <flux:checkbox wire:model="positionIsActive" label="ใช้งาน" />
            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showPositionModal', false)" variant="ghost">ยกเลิก</flux:button>
                <flux:button wire:click="savePosition" variant="primary">บันทึก</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
