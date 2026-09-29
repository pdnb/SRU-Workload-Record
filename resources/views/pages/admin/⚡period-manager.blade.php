<?php

use App\Enums\EvaluationPeriodNumber;
use App\Enums\PeriodStatus;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?int $selectedFiscalYearId = null;

    public bool $showFyModal = false;

    public bool $showPeriodModal = false;

    public ?int $editingFyId = null;

    public ?int $editingPeriodId = null;

    public int $fyYear = 2569;

    public bool $fyIsActive = false;

    public int $periodNumber = 1;

    public string $periodLabel = '';

    public string $periodStartDate = '';

    public string $periodEndDate = '';

    public string $periodStatus = 'draft';

    public function mount(): void
    {
        $this->selectedFiscalYearId = FiscalYear::query()->active()->value('id')
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
        if (! $this->selectedFiscalYearId) {
            return collect();
        }

        return EvaluationPeriod::query()
            ->where('fiscal_year_id', $this->selectedFiscalYearId)
            ->orderBy('period')
            ->get();
    }

    public function openCreateFy(): void
    {
        Gate::authorize('manage', new FiscalYear);
        $this->editingFyId = null;
        $this->fyYear = (FiscalYear::max('year') ?? 2567) + 1;
        $this->fyIsActive = false;
        $this->showFyModal = true;
    }

    public function openEditFy(int $id): void
    {
        Gate::authorize('manage', new FiscalYear);
        $fy = FiscalYear::findOrFail($id);
        $this->editingFyId = $id;
        $this->fyYear = $fy->year;
        $this->fyIsActive = $fy->is_active;
        $this->showFyModal = true;
    }

    public function saveFiscalYear(): void
    {
        Gate::authorize('manage', new FiscalYear);

        $this->validate([
            'fyYear' => ['required', 'integer', 'min:2500', 'unique:fiscal_years,year,'.($this->editingFyId ?? 'NULL')],
        ]);

        if ($this->fyIsActive) {
            FiscalYear::query()->update(['is_active' => false]);
        }

        if ($this->editingFyId) {
            FiscalYear::findOrFail($this->editingFyId)->update([
                'year' => $this->fyYear,
                'is_active' => $this->fyIsActive,
            ]);
            Flux::toast('อัปเดตปีงบประมาณแล้ว');
        } else {
            $fy = FiscalYear::create([
                'year' => $this->fyYear,
                'is_active' => $this->fyIsActive,
            ]);
            $this->selectedFiscalYearId = $fy->id;
            Flux::toast('สร้างปีงบประมาณแล้ว');
        }

        $this->showFyModal = false;
        unset($this->fiscalYears);
    }

    public function setActiveFy(int $id): void
    {
        Gate::authorize('manage', new FiscalYear);
        FiscalYear::query()->update(['is_active' => false]);
        FiscalYear::findOrFail($id)->update(['is_active' => true]);
        $this->selectedFiscalYearId = $id;
        unset($this->fiscalYears);
        Flux::toast('ตั้งเป็นปีงบประมาณปัจจุบันแล้ว');
    }

    public function openCreatePeriod(): void
    {
        Gate::authorize('manage', new EvaluationPeriod);
        $this->editingPeriodId = null;
        $this->periodNumber = 1;
        $this->periodLabel = '';
        $this->periodStartDate = '';
        $this->periodEndDate = '';
        $this->periodStatus = PeriodStatus::Draft->value;
        $this->showPeriodModal = true;
    }

    public function openEditPeriod(int $id): void
    {
        Gate::authorize('manage', new EvaluationPeriod);
        $period = EvaluationPeriod::findOrFail($id);
        $this->editingPeriodId = $id;
        $this->periodNumber = $period->period->value;
        $this->periodLabel = $period->label;
        $this->periodStartDate = $period->start_date->format('Y-m-d');
        $this->periodEndDate = $period->end_date->format('Y-m-d');
        $this->periodStatus = $period->status->value;
        $this->showPeriodModal = true;
    }

    public function savePeriod(): void
    {
        Gate::authorize('manage', new EvaluationPeriod);

        $this->validate([
            'periodNumber' => ['required', 'in:1,2'],
            'periodLabel' => ['required', 'string', 'max:255'],
            'periodStartDate' => ['required', 'date'],
            'periodEndDate' => ['required', 'date', 'after:periodStartDate'],
            'periodStatus' => ['required', 'in:draft,open,closed'],
        ]);

        $payload = [
            'fiscal_year_id' => $this->selectedFiscalYearId,
            'period' => EvaluationPeriodNumber::from((int) $this->periodNumber),
            'label' => $this->periodLabel,
            'start_date' => $this->periodStartDate,
            'end_date' => $this->periodEndDate,
            'status' => PeriodStatus::from($this->periodStatus),
        ];

        if ($this->editingPeriodId) {
            EvaluationPeriod::findOrFail($this->editingPeriodId)->update($payload);
            Flux::toast('อัปเดตรอบประเมินแล้ว');
        } else {
            EvaluationPeriod::create($payload);
            Flux::toast('สร้างรอบประเมินแล้ว');
        }

        $this->showPeriodModal = false;
        unset($this->periods);
    }

    public function togglePeriodStatus(int $id, string $status): void
    {
        Gate::authorize('manage', new EvaluationPeriod);
        EvaluationPeriod::findOrFail($id)->update(['status' => PeriodStatus::from($status)]);
        unset($this->periods);
        Flux::toast('เปลี่ยนสถานะรอบประเมินแล้ว');
    }

    public function deletePeriod(int $id): void
    {
        Gate::authorize('manage', new EvaluationPeriod);
        EvaluationPeriod::findOrFail($id)->delete();
        unset($this->periods);
        Flux::toast('ลบรอบประเมินแล้ว');
    }
}; ?>

<div>
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">จัดการรอบประเมิน</flux:heading>
            <flux:subheading>ปีงบประมาณและรอบการประเมินผลงาน</flux:subheading>
        </div>
        <div class="flex gap-2">
            <flux:button wire:click="openCreateFy" variant="ghost" icon="plus">เพิ่มปีงบประมาณ</flux:button>
            <flux:button :href="route('admin.workloads', ['fiscal_year' => $selectedFiscalYearId])" variant="ghost" wire:navigate>
                โครงสร้างภาระงาน
            </flux:button>
        </div>
    </div>

    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($this->fiscalYears as $fy)
            <div
                wire:key="fy-{{ $fy->id }}"
                @class([
                    'rounded-xl border p-4 transition',
                    'border-blue-500 bg-blue-50 dark:bg-blue-950' => $selectedFiscalYearId === $fy->id,
                    'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800' => $selectedFiscalYearId !== $fy->id,
                ])
            >
                <div class="flex items-start justify-between gap-2">
                    <button type="button" wire:click="$set('selectedFiscalYearId', {{ $fy->id }})" class="cursor-pointer text-left">
                        <div class="text-xl font-bold">ปี {{ $fy->year }}</div>
                        @if ($fy->is_active)
                            <flux:badge color="green" size="sm" class="mt-2">ใช้งานอยู่</flux:badge>
                        @endif
                    </button>
                    <div class="flex gap-1">
                        <flux:button wire:click="openEditFy({{ $fy->id }})" variant="ghost" size="xs" icon="pencil-square" />
                        @unless ($fy->is_active)
                            <flux:button wire:click="setActiveFy({{ $fy->id }})" variant="ghost" size="xs">ตั้งเป็นปัจจุบัน</flux:button>
                        @endunless
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if ($selectedFiscalYearId)
        <div class="mb-4 flex items-center justify-between">
            <flux:heading size="lg">รอบประเมิน</flux:heading>
            <flux:button wire:click="openCreatePeriod" variant="primary" size="sm" icon="plus">เพิ่มรอบ</flux:button>
        </div>

        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-3 text-left text-sm font-medium">รอบ</th>
                        <th class="px-4 py-3 text-left text-sm font-medium">ชื่อ</th>
                        <th class="px-4 py-3 text-left text-sm font-medium">ระยะเวลา</th>
                        <th class="px-4 py-3 text-left text-sm font-medium">สถานะ</th>
                        <th class="px-4 py-3 text-right text-sm font-medium">การดำเนินการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->periods as $period)
                        <tr wire:key="period-{{ $period->id }}">
                            <td class="px-4 py-3 text-sm">{{ $period->period->label() }}</td>
                            <td class="px-4 py-3 text-sm">{{ $period->label }}</td>
                            <td class="px-4 py-3 text-sm">
                                {{ $period->start_date->format('d/m/Y') }} – {{ $period->end_date->format('d/m/Y') }}
                            </td>
                            <td class="px-4 py-3">
                                <flux:badge size="sm" :color="match($period->status) {
                                    App\Enums\PeriodStatus::Open => 'green',
                                    App\Enums\PeriodStatus::Closed => 'zinc',
                                    default => 'yellow',
                                }">
                                    {{ $period->status->label() }}
                                </flux:badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1">
                                    <flux:button wire:click="openEditPeriod({{ $period->id }})" variant="ghost" size="xs" icon="pencil-square" />
                                    @if ($period->status !== App\Enums\PeriodStatus::Open)
                                        <flux:button wire:click="togglePeriodStatus({{ $period->id }}, 'open')" variant="ghost" size="xs">เปิด</flux:button>
                                    @endif
                                    @if ($period->status !== App\Enums\PeriodStatus::Closed)
                                        <flux:button wire:click="togglePeriodStatus({{ $period->id }}, 'closed')" variant="ghost" size="xs">ปิด</flux:button>
                                    @endif
                                    <flux:button wire:click="deletePeriod({{ $period->id }})" variant="ghost" size="xs" icon="trash" wire:confirm="ลบรอบประเมินนี้?" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-zinc-500">ยังไม่มีรอบประเมิน</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    <flux:modal wire:model="showFyModal" class="md:w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $editingFyId ? 'แก้ไขปีงบประมาณ' : 'เพิ่มปีงบประมาณ' }}</flux:heading>
            <flux:field>
                <flux:label>ปี (พ.ศ.)</flux:label>
                <flux:input wire:model="fyYear" type="number" />
                <flux:error name="fyYear" />
            </flux:field>
            <flux:checkbox wire:model="fyIsActive" label="ตั้งเป็นปีงบประมาณที่ใช้งาน" />
            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showFyModal', false)" variant="ghost">ยกเลิก</flux:button>
                <flux:button wire:click="saveFiscalYear" variant="primary">บันทึก</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="showPeriodModal" class="md:w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $editingPeriodId ? 'แก้ไขรอบประเมิน' : 'เพิ่มรอบประเมิน' }}</flux:heading>
            <flux:field>
                <flux:label>รอบที่</flux:label>
                <x-searchable-select wire:model="periodNumber">
                    <option value="1">รอบที่ 1</option>
                    <option value="2">รอบที่ 2</option>
                </x-searchable-select>
                <flux:error name="periodNumber" />
            </flux:field>
            <flux:field>
                <flux:label>ชื่อรอบ</flux:label>
                <flux:input wire:model="periodLabel" />
                <flux:error name="periodLabel" />
            </flux:field>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>วันเริ่ม</flux:label>
                    <flux:input wire:model="periodStartDate" type="date" />
                    <flux:error name="periodStartDate" />
                </flux:field>
                <flux:field>
                    <flux:label>วันสิ้นสุด</flux:label>
                    <flux:input wire:model="periodEndDate" type="date" />
                    <flux:error name="periodEndDate" />
                </flux:field>
            </div>
            <flux:field>
                <flux:label>สถานะ</flux:label>
                <x-searchable-select wire:model="periodStatus">
                    <option value="draft">ร่าง</option>
                    <option value="open">เปิดรับ</option>
                    <option value="closed">ปิดแล้ว</option>
                </x-searchable-select>
                <flux:error name="periodStatus" />
            </flux:field>
            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showPeriodModal', false)" variant="ghost">ยกเลิก</flux:button>
                <flux:button wire:click="savePeriod" variant="primary">บันทึก</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
