<?php

use App\Actions\CloneWorkload;
use App\Actions\CloneWorkloadGroup;
use App\Actions\CloneWorkloadMain;
use App\Actions\CopyStructureToNextFiscalYear;
use App\Actions\ImportWorkloadStructureFromPmsHtml;
use App\Models\FiscalYear;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use App\Services\PmsWorkloadHtmlParser;
use App\Support\WorkloadStructureNumbering;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;
    #[Url(as: 'fiscal_year')]
    public ?int $selectedFiscalYearId = null;

    public bool $showGroupModal = false;

    public bool $showMainModal = false;

    public bool $showWorkloadModal = false;

    public bool $showCopyModal = false;

    public bool $showImportModal = false;

    public bool $replaceExisting = false;

    public bool $replaceExistingOnImport = false;

    public $pmsHtmlFile = null;

    /** @var array{groups: int, mains: int, workloads: int}|null */
    public ?array $importPreview = null;

    public ?int $editingGroupId = null;

    public ?int $editingMainId = null;

    public ?int $editingWorkloadId = null;

    public ?int $parentGroupId = null;

    public ?int $parentMainId = null;

    public string $groupName = '';

    public string $mainName = '';

    public string $workloadName = '';

    public ?string $groupMaxScore = null;

    public ?string $mainMaxScore = null;

    public ?string $workloadScore = null;

    public function mount(): void
    {
        $this->selectedFiscalYearId ??= FiscalYear::query()->active()->value('id')
            ?? FiscalYear::query()->orderByDesc('year')->value('id');
    }

    #[Computed]
    public function fiscalYears()
    {
        return FiscalYear::query()->orderByDesc('year')->get();
    }

    #[Computed]
    public function selectedFiscalYear()
    {
        return $this->selectedFiscalYearId
            ? FiscalYear::find($this->selectedFiscalYearId)
            : null;
    }

    #[Computed]
    public function workloadGroups()
    {
        if (! $this->selectedFiscalYearId) {
            return collect();
        }

        return WorkloadGroup::query()
            ->forFiscalYear($this->selectedFiscalYearId)
            ->with(['workloadMains.workloads'])
            ->get();
    }

    #[Computed]
    public function structureNumbers(): array
    {
        return WorkloadStructureNumbering::buildMap($this->workloadGroups);
    }

    public function openCreateGroup(): void
    {
        Gate::authorize('manage', WorkloadGroup::make());
        $this->resetGroupForm();
        $this->showGroupModal = true;
    }

    public function openEditGroup(int $id): void
    {
        $group = WorkloadGroup::findOrFail($id);
        Gate::authorize('manage', $group);
        $this->editingGroupId = $id;
        $this->groupName = $group->name;
        $this->groupMaxScore = $group->max_score !== null ? (string) $group->max_score : '';
        $this->showGroupModal = true;
    }

    public function saveGroup(): void
    {
        $this->validate([
            'groupName' => ['required', 'string', 'max:255'],
            'groupMaxScore' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
        ]);

        $maxScore = filled($this->groupMaxScore) ? $this->groupMaxScore : null;

        if ($this->editingGroupId) {
            $group = WorkloadGroup::findOrFail($this->editingGroupId);
            Gate::authorize('manage', $group);
            $group->update([
                'name' => $this->groupName,
                'max_score' => $maxScore,
            ]);
            Flux::toast('อัปเดตกลุ่มแล้ว');
        } else {
            Gate::authorize('manage', WorkloadGroup::make());
            WorkloadGroup::create([
                'fiscal_year_id' => $this->selectedFiscalYearId,
                'name' => $this->groupName,
                'max_score' => $maxScore,
                'sort_order' => WorkloadGroup::where('fiscal_year_id', $this->selectedFiscalYearId)->max('sort_order') + 1,
            ]);
            Flux::toast('สร้างกลุ่มแล้ว');
        }

        $this->showGroupModal = false;
        unset($this->workloadGroups);
    }

    public function deleteGroup(int $id): void
    {
        $group = WorkloadGroup::findOrFail($id);
        Gate::authorize('manage', $group);
        $group->delete();
        unset($this->workloadGroups);
        Flux::toast('ลบกลุ่มแล้ว');
    }

    public function cloneGroup(int $id): void
    {
        $group = WorkloadGroup::findOrFail($id);
        Gate::authorize('manage', $group);
        app(CloneWorkloadGroup::class)($group);
        unset($this->workloadGroups);
        Flux::toast('สำเนากลุ่มแล้ว');
    }

    public function openCreateMain(int $groupId): void
    {
        Gate::authorize('manage', WorkloadGroup::findOrFail($groupId));
        $this->resetMainForm();
        $this->parentGroupId = $groupId;
        $this->showMainModal = true;
    }

    public function openEditMain(int $id): void
    {
        $main = WorkloadMain::findOrFail($id);
        Gate::authorize('manage', $main->workloadGroup);
        $this->editingMainId = $id;
        $this->parentGroupId = $main->workload_group_id;
        $this->mainName = $main->name;
        $this->mainMaxScore = $main->max_score !== null ? (string) $main->max_score : '';
        $this->showMainModal = true;
    }

    public function saveMain(): void
    {
        $this->validate([
            'mainName' => ['required', 'string', 'max:255'],
            'mainMaxScore' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
        ]);

        $maxScore = filled($this->mainMaxScore) ? $this->mainMaxScore : null;

        if ($this->editingMainId) {
            $main = WorkloadMain::findOrFail($this->editingMainId);
            Gate::authorize('manage', $main->workloadGroup);
            $main->update([
                'name' => $this->mainName,
                'max_score' => $maxScore,
            ]);
            Flux::toast('อัปเดตหมวดหลักแล้ว');
        } else {
            $group = WorkloadGroup::findOrFail($this->parentGroupId);
            Gate::authorize('manage', $group);
            WorkloadMain::create([
                'workload_group_id' => $this->parentGroupId,
                'name' => $this->mainName,
                'max_score' => $maxScore,
                'sort_order' => WorkloadMain::where('workload_group_id', $this->parentGroupId)->max('sort_order') + 1,
            ]);
            Flux::toast('สร้างหมวดหลักแล้ว');
        }

        $this->showMainModal = false;
        unset($this->workloadGroups);
    }

    public function deleteMain(int $id): void
    {
        $main = WorkloadMain::findOrFail($id);
        Gate::authorize('manage', $main->workloadGroup);
        $main->delete();
        unset($this->workloadGroups);
        Flux::toast('ลบหมวดหลักแล้ว');
    }

    public function cloneMain(int $id): void
    {
        $main = WorkloadMain::with('workloads')->findOrFail($id);
        Gate::authorize('manage', $main->workloadGroup);
        app(CloneWorkloadMain::class)($main);
        unset($this->workloadGroups);
        Flux::toast('สำเนาหมวดหลักแล้ว');
    }

    public function openCreateWorkload(int $mainId): void
    {
        $main = WorkloadMain::with('workloadGroup')->findOrFail($mainId);
        Gate::authorize('manage', $main->workloadGroup);
        $this->resetWorkloadForm();
        $this->parentMainId = $mainId;
        $this->showWorkloadModal = true;
    }

    public function openEditWorkload(int $id): void
    {
        $workload = Workload::with('workloadMain.workloadGroup')->findOrFail($id);
        Gate::authorize('manage', $workload->workloadMain->workloadGroup);
        $this->editingWorkloadId = $id;
        $this->parentMainId = $workload->workload_main_id;
        $this->workloadName = $workload->name;
        $this->workloadScore = $workload->score !== null ? (string) $workload->score : '';
        $this->showWorkloadModal = true;
    }

    public function saveWorkload(): void
    {
        $this->validate([
            'workloadName' => ['required', 'string', 'max:255'],
            'workloadScore' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
        ]);

        $score = filled($this->workloadScore) ? $this->workloadScore : null;

        if ($this->editingWorkloadId) {
            $workload = Workload::with('workloadMain.workloadGroup')->findOrFail($this->editingWorkloadId);
            Gate::authorize('manage', $workload->workloadMain->workloadGroup);
            $workload->update([
                'name' => $this->workloadName,
                'score' => $score,
            ]);
            Flux::toast('อัปเดตภาระงานแล้ว');
        } else {
            $main = WorkloadMain::with('workloadGroup')->findOrFail($this->parentMainId);
            Gate::authorize('manage', $main->workloadGroup);
            Workload::create([
                'workload_main_id' => $this->parentMainId,
                'name' => $this->workloadName,
                'score' => $score,
                'sort_order' => Workload::where('workload_main_id', $this->parentMainId)->max('sort_order') + 1,
            ]);
            Flux::toast('สร้างภาระงานแล้ว');
        }

        $this->showWorkloadModal = false;
        unset($this->workloadGroups);
    }

    public function deleteWorkload(int $id): void
    {
        $workload = Workload::with('workloadMain.workloadGroup')->findOrFail($id);
        Gate::authorize('manage', $workload->workloadMain->workloadGroup);
        $workload->delete();
        unset($this->workloadGroups);
        Flux::toast('ลบภาระงานแล้ว');
    }

    public function cloneWorkload(int $id): void
    {
        $workload = Workload::with('workloadMain.workloadGroup')->findOrFail($id);
        Gate::authorize('manage', $workload->workloadMain->workloadGroup);
        app(CloneWorkload::class)($workload);
        unset($this->workloadGroups);
        Flux::toast('สำเนาภาระงานแล้ว');
    }

    public function openCopyModal(): void
    {
        Gate::authorize('manage', WorkloadGroup::make());
        $this->replaceExisting = false;
        $this->showCopyModal = true;
    }

    public function copyToNextFiscalYear(): void
    {
        $source = FiscalYear::findOrFail($this->selectedFiscalYearId);
        Gate::authorize('manage', WorkloadGroup::make());

        try {
            $target = app(CopyStructureToNextFiscalYear::class)($source, $this->replaceExisting);
            $this->selectedFiscalYearId = $target->id;
            $this->showCopyModal = false;
            unset($this->workloadGroups, $this->fiscalYears, $this->selectedFiscalYear);
            Flux::toast("คัดลอกโครงสร้างภาระงานและรอบประเมินไปยังปีงบประมาณ {$target->year} เรียบร้อยแล้ว");
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'target_has_structure') {
                Flux::toast('ปีงบประมาณเป้าหมายมีโครงสร้างอยู่แล้ว — เปิดตัวเลือกแทนที่โครงสร้างเดิม', variant: 'warning');
            } else {
                throw $e;
            }
        }
    }

    public function openImportModal(): void
    {
        Gate::authorize('manage', WorkloadGroup::make());
        $this->resetImportForm();
        $this->showImportModal = true;
    }

    public function updatedPmsHtmlFile(): void
    {
        $this->importPreview = null;
        $this->resetErrorBag('pmsHtmlFile');

        $this->validate([
            'pmsHtmlFile' => ['required', 'file', 'mimes:html,htm', 'max:2048'],
        ]);

        $html = $this->pmsHtmlFile->get();
        $structure = app(PmsWorkloadHtmlParser::class)->parse($html);

        if (count($structure) === 0) {
            $this->addError('pmsHtmlFile', 'รูปแบบไฟล์ไม่ถูกต้อง');
            $this->pmsHtmlFile = null;

            return;
        }

        $this->importPreview = app(PmsWorkloadHtmlParser::class)->stats($structure);
    }

    public function importFromPmsHtml(): void
    {
        Gate::authorize('manage', WorkloadGroup::make());

        $this->validate([
            'pmsHtmlFile' => ['required', 'file', 'mimes:html,htm', 'max:2048'],
        ]);

        $fiscalYear = FiscalYear::findOrFail($this->selectedFiscalYearId);
        $parser = app(PmsWorkloadHtmlParser::class);
        $structure = $parser->parse($this->pmsHtmlFile->get());

        if (count($structure) === 0) {
            $this->addError('pmsHtmlFile', 'รูปแบบไฟล์ไม่ถูกต้อง');

            return;
        }

        try {
            app(ImportWorkloadStructureFromPmsHtml::class)($fiscalYear, $structure, $this->replaceExistingOnImport);
            $stats = $parser->stats($structure);
            $this->showImportModal = false;
            $this->resetImportForm();
            unset($this->workloadGroups);
            Flux::toast("นำเข้าโครงสร้างภาระงานปี {$fiscalYear->year} เรียบร้อยแล้ว ({$stats['groups']} กลุ่ม / {$stats['mains']} หมวด / {$stats['workloads']} ภาระงาน)");
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'fiscal_year_has_structure') {
                Flux::toast('ปีงบประมาณนี้มีโครงสร้างอยู่แล้ว — เปิดตัวเลือกแทนที่โครงสร้างเดิม', variant: 'warning');
            } else {
                throw $e;
            }
        }
    }

    protected function resetImportForm(): void
    {
        $this->pmsHtmlFile = null;
        $this->importPreview = null;
        $this->replaceExistingOnImport = false;
    }

    protected function resetGroupForm(): void
    {
        $this->editingGroupId = null;
        $this->groupName = '';
        $this->groupMaxScore = '';
    }

    protected function resetMainForm(): void
    {
        $this->editingMainId = null;
        $this->parentGroupId = null;
        $this->mainName = '';
        $this->mainMaxScore = '';
    }

    protected function resetWorkloadForm(): void
    {
        $this->editingWorkloadId = null;
        $this->parentMainId = null;
        $this->workloadName = '';
        $this->workloadScore = '';
    }
}; ?>

<div>
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">โครงสร้างภาระงาน</flux:heading>
            <flux:subheading>จัดการกลุ่ม หมวด และรายการภาระงานตามปีงบประมาณ</flux:subheading>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($this->selectedFiscalYear)
                <flux:button wire:click="openImportModal" variant="ghost" icon="arrow-up-tray">
                    นำเข้าจาก PCM
                </flux:button>
                <flux:button wire:click="openCopyModal" variant="ghost" icon="document-duplicate">
                    คัดลอกไปยังปีงบประมาณหน้า
                </flux:button>
            @endif
            <flux:button wire:click="openCreateGroup" variant="primary" icon="plus">เพิ่มกลุ่ม</flux:button>
        </div>
    </div>

    <flux:field class="mb-8 max-w-xs">
        <flux:label>ปีงบประมาณ</flux:label>
        <flux:select wire:model.live="selectedFiscalYearId">
            @foreach ($this->fiscalYears as $fy)
                <option wire:key="fy-sel-{{ $fy->id }}" value="{{ $fy->id }}">
                    {{ $fy->year }}@if($fy->is_active) (ใช้งานอยู่)@endif
                </option>
            @endforeach
        </flux:select>
    </flux:field>

    <div class="space-y-6">
        @forelse ($this->workloadGroups as $group)
            <div
                wire:key="group-{{ $group->id }}"
                class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800"
            >
                <div class="flex items-center justify-between gap-4 border-b border-zinc-100 px-4 py-3 dark:border-zinc-700">
                    <div class="flex min-w-0 flex-1 items-center gap-2">
                        <span class="shrink-0 tabular-nums text-zinc-400">{{ $this->structureNumbers['groups'][$group->id] }}.</span>
                        <flux:heading size="lg" class="truncate">{{ $group->name }}</flux:heading>
                        @if ($group->max_score !== null)
                            <flux:badge size="sm" color="zinc">สูงสุด {{ number_format($group->max_score, 2) }} คะแนน</flux:badge>
                        @endif
                    </div>
                    <div class="flex gap-1">
                        <flux:button wire:click="openCreateMain({{ $group->id }})" variant="ghost" size="xs" icon="plus">หมวด</flux:button>
                        <flux:button wire:click="cloneGroup({{ $group->id }})" variant="ghost" size="xs" icon="document-duplicate" title="สำเนา" />
                        <flux:button wire:click="openEditGroup({{ $group->id }})" variant="ghost" size="xs" icon="pencil-square" />
                        <flux:button wire:click="deleteGroup({{ $group->id }})" variant="ghost" size="xs" icon="trash" wire:confirm="ลบกลุ่มนี้และข้อมูลย่อยทั้งหมด?" />
                    </div>
                </div>

                @foreach ($group->workloadMains as $main)
                    <div wire:key="main-{{ $main->id }}" class="border-b border-zinc-50 px-4 py-3 last:border-0 dark:border-zinc-900">
                        <div class="mb-2 flex items-center justify-between">
                            <div class="flex min-w-0 flex-1 items-center gap-2 font-medium text-zinc-700 dark:text-zinc-300">
                                <span class="shrink-0 tabular-nums text-zinc-400">{{ $this->structureNumbers['mains'][$main->id] }}</span>
                                <span class="truncate">{{ $main->name }}</span>
                                @if ($main->max_score !== null)
                                    <flux:badge size="sm" color="zinc">สูงสุด {{ number_format($main->max_score, 2) }} คะแนน</flux:badge>
                                @endif
                            </div>
                            <div class="flex gap-1">
                                <flux:button wire:click="openCreateWorkload({{ $main->id }})" variant="ghost" size="xs" icon="plus">ภาระงาน</flux:button>
                                <flux:button wire:click="cloneMain({{ $main->id }})" variant="ghost" size="xs" icon="document-duplicate" title="สำเนา" />
                                <flux:button wire:click="openEditMain({{ $main->id }})" variant="ghost" size="xs" icon="pencil-square" />
                                <flux:button wire:click="deleteMain({{ $main->id }})" variant="ghost" size="xs" icon="trash" wire:confirm="ลบหมวดนี้?" />
                            </div>
                        </div>

                        <ul class="space-y-1 pl-4">
                            @foreach ($main->workloads as $workload)
                                <li wire:key="wl-{{ $workload->id }}" class="flex items-center justify-between rounded-lg px-2 py-1 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-900">
                                    <div class="flex min-w-0 flex-1 items-center gap-2">
                                        <span class="shrink-0 tabular-nums text-zinc-400">{{ $this->structureNumbers['workloads'][$workload->id] }}</span>
                                        <span class="truncate">{{ $workload->name }}</span>
                                        @if ($workload->score !== null)
                                            <flux:badge size="sm" color="blue">{{ number_format($workload->score, 2) }} คะแนน</flux:badge>
                                        @endif
                                    </div>
                                    <div class="flex gap-1">
                                        <flux:button wire:click="cloneWorkload({{ $workload->id }})" variant="ghost" size="xs" icon="document-duplicate" title="สำเนา" />
                                        <flux:button wire:click="openEditWorkload({{ $workload->id }})" variant="ghost" size="xs" icon="pencil-square" />
                                        <flux:button wire:click="deleteWorkload({{ $workload->id }})" variant="ghost" size="xs" icon="trash" wire:confirm="ลบภาระงานนี้?" />
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @empty
            <x-empty-state icon="folder-open" message="ยังไม่มีโครงสร้างภาระงานสำหรับปีนี้">
                <flux:button wire:click="openCreateGroup" variant="primary">เพิ่มกลุ่มแรก</flux:button>
            </x-empty-state>
        @endforelse
    </div>

    <flux:modal wire:model="showGroupModal" class="md:w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $editingGroupId ? 'แก้ไขกลุ่ม' : 'เพิ่มกลุ่ม' }}</flux:heading>
            <flux:field>
                <flux:label>ชื่อกลุ่ม</flux:label>
                <flux:input wire:model="groupName" />
                <flux:error name="groupName" />
            </flux:field>
            <flux:field>
                <flux:label>คะแนนสูงสุด</flux:label>
                <flux:input wire:model="groupMaxScore" type="number" step="0.01" min="0" placeholder="ไม่ระบุ" />
                <flux:description>ไม่บังคับ — ใช้เป็นค่าอ้างอิงเพดานคะแนนของกลุ่ม</flux:description>
                <flux:error name="groupMaxScore" />
            </flux:field>
            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showGroupModal', false)" variant="ghost">ยกเลิก</flux:button>
                <flux:button wire:click="saveGroup" variant="primary">บันทึก</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="showMainModal" class="md:w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $editingMainId ? 'แก้ไขหมวดหลัก' : 'เพิ่มหมวดหลัก' }}</flux:heading>
            <flux:field>
                <flux:label>ชื่อหมวด</flux:label>
                <flux:input wire:model="mainName" />
                <flux:error name="mainName" />
            </flux:field>
            <flux:field>
                <flux:label>คะแนนสูงสุด</flux:label>
                <flux:input wire:model="mainMaxScore" type="number" step="0.01" min="0" placeholder="ไม่ระบุ" />
                <flux:description>ไม่บังคับ — ใช้เป็นค่าอ้างอิงเพดานคะแนนของหมวด</flux:description>
                <flux:error name="mainMaxScore" />
            </flux:field>
            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showMainModal', false)" variant="ghost">ยกเลิก</flux:button>
                <flux:button wire:click="saveMain" variant="primary">บันทึก</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="showWorkloadModal" class="md:w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $editingWorkloadId ? 'แก้ไขภาระงาน' : 'เพิ่มภาระงาน' }}</flux:heading>
            <flux:field>
                <flux:label>ชื่อภาระงาน</flux:label>
                <flux:input wire:model="workloadName" />
                <flux:error name="workloadName" />
            </flux:field>
            <flux:field>
                <flux:label>คะแนน</flux:label>
                <flux:input wire:model="workloadScore" type="number" step="0.01" min="0" placeholder="ไม่ระบุ" />
                <flux:error name="workloadScore" />
            </flux:field>
            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showWorkloadModal', false)" variant="ghost">ยกเลิก</flux:button>
                <flux:button wire:click="saveWorkload" variant="primary">บันทึก</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="showImportModal" class="md:w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">นำเข้าจาก PCM</flux:heading>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                อัปโหลดไฟล์ HTML ที่ export จากระบบ PCM เพื่อนำเข้าโครงสร้างภาระงานไปยังปีงบประมาณ
                <strong>{{ $this->selectedFiscalYear?->year }}</strong>
            </p>
            <flux:field>
                <flux:label>ไฟล์ PMS.html</flux:label>
                <input
                    type="file"
                    wire:model="pmsHtmlFile"
                    accept=".html,.htm"
                    class="block w-full text-sm text-zinc-600 file:mr-4 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium dark:text-zinc-400 dark:file:bg-zinc-800"
                />
                <flux:error name="pmsHtmlFile" />
            </flux:field>
            @if ($importPreview)
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="font-medium text-zinc-700 dark:text-zinc-300">ตัวอย่างข้อมูลที่จะนำเข้า</p>
                    <p class="mt-1 text-zinc-600 dark:text-zinc-400">
                        กลุ่ม {{ $importPreview['groups'] }} /
                        หมวด {{ $importPreview['mains'] }} /
                        ภาระงาน {{ $importPreview['workloads'] }}
                    </p>
                </div>
            @endif
            <flux:checkbox
                wire:model="replaceExistingOnImport"
                label="แทนที่โครงสร้างเดิม (ถ้ามีอยู่แล้ว)"
            />
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                การแทนที่จะลบโครงสร้างเดิมทั้งหมด รวมถึงบันทึกภาระงานที่อ้างอิงรายการภาระงานเดิม
            </p>
            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showImportModal', false)" variant="ghost">ยกเลิก</flux:button>
                @if ($pmsHtmlFile && $importPreview)
                    <flux:button
                        wire:click="importFromPmsHtml"
                        variant="primary"
                        wire:loading.attr="disabled"
                    >
                        นำเข้า
                    </flux:button>
                @else
                    <flux:button variant="primary" disabled>
                        นำเข้า
                    </flux:button>
                @endif
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="showCopyModal" class="md:w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">คัดลอกไปยังปีงบประมาณหน้า</flux:heading>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                ระบบจะคัดลอกโครงสร้างภาระงานและรอบประเมินจากปี
                <strong>{{ $this->selectedFiscalYear?->year }}</strong>
                ไปยังปี <strong>{{ ($this->selectedFiscalYear?->year ?? 0) + 1 }}</strong>
                (สถานะรอบประเมินใหม่จะเป็น "ร่าง")
            </p>
            <flux:checkbox wire:model="replaceExisting" label="แทนที่โครงสร้างเดิม (ถ้ามีอยู่แล้ว)" />
            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showCopyModal', false)" variant="ghost">ยกเลิก</flux:button>
                <flux:button wire:click="copyToNextFiscalYear" variant="primary" wire:loading.attr="disabled">
                    คัดลอก
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
