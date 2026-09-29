<?php

use App\Actions\CloneWorkload;
use App\Actions\CloneWorkloadGroup;
use App\Actions\CloneWorkloadMain;
use App\Actions\CopyStructureToNextFiscalYear;
use App\Actions\CopyStructureToPosition;
use App\Actions\ImportWorkloadStructureFromPmsHtml;
use App\Actions\Oracle\RunOracleSync;
use App\Models\FiscalYear;
use App\Models\OracleSyncRun;
use App\Models\Position;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use App\Models\WorkloadStructure;
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

    #[Url(as: 'position', history: true)]
    public ?int $selectedPositionId = null;

    #[Url(as: 'q', except: '')]
    public string $positionSearch = '';

    public bool $showCopyPositionModal = false;

    public ?int $copyTargetPositionId = null;

    public bool $replaceExistingOnCopyPosition = false;

    /** @var array{code: string, name: string}|null */
    public ?array $importDetectedPosition = null;

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

    public function updatedSelectedFiscalYearId(): void
    {
        unset($this->selectedStructure, $this->workloadGroups, $this->positionSummaries);
    }

    public function updatedSelectedPositionId(): void
    {
        unset($this->selectedStructure, $this->workloadGroups, $this->selectedPosition);
    }

    public function updatedPositionSearch(): void
    {
        unset($this->positionSummaries);
    }

    public function selectPosition(int $id): void
    {
        $this->selectedPositionId = Position::findOrFail($id)->id;
        unset($this->selectedStructure, $this->workloadGroups, $this->selectedPosition);
    }

    public function backToList(): void
    {
        $this->selectedPositionId = null;
        unset($this->selectedStructure, $this->workloadGroups, $this->selectedPosition, $this->positionSummaries);
    }

    #[Computed]
    public function positions()
    {
        return Position::query()->orderByDesc('is_active')->orderBy('code')->get();
    }

    /**
     * Positions with a summary of their structure in the selected fiscal year.
     *
     * @return \Illuminate\Support\Collection<int, array{position: Position, structure: ?WorkloadStructure, groups: int, mains: int, workloads: int}>
     */
    #[Computed]
    public function positionSummaries()
    {
        $search = trim($this->positionSearch);

        $positions = Position::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")))
            ->orderByDesc('is_active')
            ->orderBy('code')
            ->get();

        $structures = WorkloadStructure::query()
            ->where('fiscal_year_id', $this->selectedFiscalYearId)
            ->whereIn('position_id', $positions->pluck('id'))
            ->withCount('workloadGroups')
            ->get()
            ->keyBy('position_id');

        $structureIds = $structures->pluck('id');

        $mainCounts = WorkloadMain::query()
            ->join('workload_groups', 'workload_groups.id', '=', 'workload_mains.workload_group_id')
            ->whereIn('workload_groups.workload_structure_id', $structureIds)
            ->groupBy('workload_groups.workload_structure_id')
            ->selectRaw('workload_groups.workload_structure_id as structure_id, count(*) as aggregate')
            ->pluck('aggregate', 'structure_id');

        $workloadCounts = Workload::query()
            ->join('workload_mains', 'workload_mains.id', '=', 'workloads.workload_main_id')
            ->join('workload_groups', 'workload_groups.id', '=', 'workload_mains.workload_group_id')
            ->whereIn('workload_groups.workload_structure_id', $structureIds)
            ->groupBy('workload_groups.workload_structure_id')
            ->selectRaw('workload_groups.workload_structure_id as structure_id, count(*) as aggregate')
            ->pluck('aggregate', 'structure_id');

        return $positions->map(function (Position $position) use ($structures, $mainCounts, $workloadCounts) {
            $structure = $structures->get($position->id);

            return [
                'position' => $position,
                'structure' => $structure,
                'groups' => (int) ($structure?->workload_groups_count ?? 0),
                'mains' => (int) ($structure ? $mainCounts->get($structure->id, 0) : 0),
                'workloads' => (int) ($structure ? $workloadCounts->get($structure->id, 0) : 0),
            ];
        });
    }

    #[Computed]
    public function selectedPosition(): ?Position
    {
        return $this->selectedPositionId ? Position::find($this->selectedPositionId) : null;
    }

    #[Computed]
    public function selectedStructure(): ?WorkloadStructure
    {
        if (! $this->selectedFiscalYearId || ! $this->selectedPositionId) {
            return null;
        }

        return WorkloadStructure::query()
            ->where('fiscal_year_id', $this->selectedFiscalYearId)
            ->where('position_id', $this->selectedPositionId)
            ->first();
    }

    #[Computed]
    public function isLocked(): bool
    {
        return (bool) $this->selectedStructure?->isFromOracle();
    }

    #[Computed]
    public function latestSyncRun(): ?OracleSyncRun
    {
        return OracleSyncRun::query()->where('dry_run', false)->latest('id')->first();
    }

    protected function groupInSelectedStructure(): WorkloadGroup
    {
        return WorkloadGroup::make(['workload_structure_id' => $this->selectedStructure?->id]);
    }

    protected function ensureSelectedStructure(): WorkloadStructure
    {
        abort_unless($this->selectedFiscalYearId && $this->selectedPositionId, 422);

        $structure = WorkloadStructure::firstOrCreate([
            'fiscal_year_id' => $this->selectedFiscalYearId,
            'position_id' => $this->selectedPositionId,
        ]);

        unset($this->selectedStructure);

        return $structure;
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
        if (! $this->selectedStructure) {
            return collect();
        }

        return WorkloadGroup::query()
            ->forStructure($this->selectedStructure->id)
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
        Gate::authorize('manage', $this->groupInSelectedStructure());
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
            Gate::authorize('manage', $this->groupInSelectedStructure());
            $structure = $this->ensureSelectedStructure();
            WorkloadGroup::create([
                'workload_structure_id' => $structure->id,
                'name' => $this->groupName,
                'max_score' => $maxScore,
                'sort_order' => WorkloadGroup::where('workload_structure_id', $structure->id)->max('sort_order') + 1,
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
            unset($this->workloadGroups, $this->fiscalYears, $this->selectedFiscalYear, $this->selectedStructure, $this->positionSummaries);
            Flux::toast("คัดลอกโครงสร้างภาระงานและรอบประเมินไปยังปีงบประมาณ {$target->year} เรียบร้อยแล้ว");
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'target_has_structure') {
                Flux::toast('ปีงบประมาณเป้าหมายมีโครงสร้างอยู่แล้ว — เปิดตัวเลือกแทนที่โครงสร้างเดิม', variant: 'warning');
            } else {
                throw $e;
            }
        }
    }

    public function openCopyPositionModal(): void
    {
        Gate::authorize('manage', WorkloadGroup::make());
        abort_unless($this->selectedStructure, 404);
        $this->copyTargetPositionId = null;
        $this->replaceExistingOnCopyPosition = false;
        $this->showCopyPositionModal = true;
    }

    public function copyToPosition(): void
    {
        Gate::authorize('manage', WorkloadGroup::make());

        $this->validate([
            'copyTargetPositionId' => ['required', 'integer', 'exists:positions,id', 'different:selectedPositionId'],
        ], [], ['copyTargetPositionId' => 'ตำแหน่งปลายทาง']);

        $source = $this->selectedStructure;
        abort_unless($source, 404);
        $targetPosition = Position::findOrFail($this->copyTargetPositionId);

        try {
            app(CopyStructureToPosition::class)($source, $targetPosition, $this->replaceExistingOnCopyPosition);
            $this->showCopyPositionModal = false;
            $this->selectedPositionId = $targetPosition->id;
            unset($this->workloadGroups, $this->selectedStructure, $this->selectedPosition);
            Flux::toast("คัดลอกโครงสร้างไปยังตำแหน่ง {$targetPosition->label()} เรียบร้อยแล้ว");
        } catch (\RuntimeException $e) {
            match ($e->getMessage()) {
                'target_has_structure' => Flux::toast('ตำแหน่งปลายทางมีโครงสร้างอยู่แล้ว — เปิดตัวเลือกแทนที่โครงสร้างเดิม', variant: 'warning'),
                'target_is_oracle' => Flux::toast('ตำแหน่งปลายทางใช้โครงสร้างจาก PCM — ไม่สามารถแทนที่ได้', variant: 'warning'),
                default => throw $e,
            };
        }
    }

    public function syncFromOracle(): void
    {
        Gate::authorize('manage', WorkloadGroup::make());

        if (! config('oracle_workload.enabled')) {
            Flux::toast('ยังไม่ได้เปิดใช้งานการซิงก์ PCM (ORACLE_SYNC_ENABLED)', variant: 'warning');

            return;
        }

        $run = app(RunOracleSync::class)(year: $this->selectedFiscalYear?->year);

        unset($this->workloadGroups, $this->selectedStructure, $this->positions, $this->latestSyncRun, $this->positionSummaries);

        $run->status === 'success'
            ? Flux::toast('ซิงก์ข้อมูลจาก PCM เรียบร้อยแล้ว')
            : Flux::toast('ซิงก์จาก PCM ไม่สำเร็จ: '.$run->error, variant: 'danger');
    }

    public function openImportModal(): void
    {
        Gate::authorize('manage', $this->groupInSelectedStructure());
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
        $parser = app(PmsWorkloadHtmlParser::class);
        $structure = $parser->parse($html);

        if (count($structure) === 0) {
            $this->addError('pmsHtmlFile', 'รูปแบบไฟล์ไม่ถูกต้อง');
            $this->pmsHtmlFile = null;

            return;
        }

        $this->importPreview = $parser->stats($structure);
        $this->importDetectedPosition = $parser->parsePosition($html);
    }

    public function importFromPmsHtml(): void
    {
        Gate::authorize('manage', $this->groupInSelectedStructure());

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
            app(ImportWorkloadStructureFromPmsHtml::class)($this->ensureSelectedStructure(), $structure, $this->replaceExistingOnImport);
            $stats = $parser->stats($structure);
            $this->showImportModal = false;
            $this->resetImportForm();
            unset($this->workloadGroups);
            Flux::toast("นำเข้าโครงสร้างภาระงานปี {$fiscalYear->year} ตำแหน่ง {$this->selectedPosition?->label()} เรียบร้อยแล้ว ({$stats['groups']} กลุ่ม / {$stats['mains']} หมวด / {$stats['workloads']} ภาระงาน)");
        } catch (\RuntimeException $e) {
            match ($e->getMessage()) {
                'fiscal_year_has_structure' => Flux::toast('ตำแหน่งนี้มีโครงสร้างในปีงบประมาณนี้อยู่แล้ว — เปิดตัวเลือกแทนที่โครงสร้างเดิม', variant: 'warning'),
                'structure_is_oracle' => Flux::toast('โครงสร้างนี้ซิงก์จาก PCM — ไม่สามารถนำเข้าทับได้', variant: 'warning'),
                default => throw $e,
            };
        }
    }

    protected function resetImportForm(): void
    {
        $this->pmsHtmlFile = null;
        $this->importPreview = null;
        $this->importDetectedPosition = null;
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
        <div class="min-w-0">
            @if ($selectedPositionId)
                <flux:heading size="xl">{{ $this->selectedPosition?->label() }}</flux:heading>
                <flux:subheading>โครงสร้างภาระงาน ปีงบประมาณ {{ $this->selectedFiscalYear?->year }}</flux:subheading>
            @else
                <flux:heading size="xl">โครงสร้างภาระงาน</flux:heading>
                <flux:subheading>เลือกตำแหน่งเพื่อดูและจัดการกลุ่ม หมวด และรายการภาระงาน</flux:subheading>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($selectedPositionId)
                <flux:button wire:click="backToList" variant="ghost" icon="arrow-left">
                    กลับไปรายการตำแหน่ง
                </flux:button>
                @if ($this->selectedFiscalYear)
                    @unless ($this->isLocked)
                        <flux:button wire:click="openImportModal" variant="ghost" icon="arrow-up-tray">
                            นำเข้าจาก PCM
                        </flux:button>
                    @endunless
                @endif
                @if ($this->selectedFiscalYear)
                    @if ($this->selectedStructure)
                        <flux:button wire:click="openCopyPositionModal" variant="ghost" icon="document-duplicate">
                            คัดลอกไปยังตำแหน่งอื่น
                        </flux:button>
                    @endif
                @endif
                @unless ($this->isLocked)
                    <flux:button wire:click="openCreateGroup" variant="primary" icon="plus">เพิ่มกลุ่ม</flux:button>
                @endunless
            @else
                @if (config('oracle_workload.enabled'))
                    <flux:button wire:click="syncFromOracle" variant="ghost" icon="arrow-path" wire:loading.attr="disabled" wire:target="syncFromOracle">
                        ซิงก์จาก PCM
                    </flux:button>
                @endif
                @if ($this->selectedFiscalYear)
                    <flux:button wire:click="openCopyModal" variant="ghost" icon="document-duplicate">
                        คัดลอกไปยังปีงบประมาณหน้า
                    </flux:button>
                @endif
            @endif
        </div>
    </div>

    @if ($selectedPositionId)
        @if ($this->selectedStructure?->isFromOracle())
            <div class="mb-6 flex items-center gap-2">
                <flux:badge color="amber" icon="lock-closed">PCM</flux:badge>
                <span class="text-xs text-zinc-500 dark:text-zinc-400">
                    ซิงก์ล่าสุด {{ $this->selectedStructure->synced_at?->format('d/m/Y H:i') ?? '-' }} — แก้ไขได้ที่ระบบต้นทางเท่านั้น
                </span>
            </div>
        @endif
    @else
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end">
            <flux:field class="w-full sm:max-w-xs">
                <flux:label>ปีงบประมาณ</flux:label>
                <x-searchable-select wire:model.live="selectedFiscalYearId">
                    @foreach ($this->fiscalYears as $fy)
                        <option wire:key="fy-sel-{{ $fy->id }}" value="{{ $fy->id }}">
                            {{ $fy->year }}@if($fy->is_active) (ใช้งานอยู่)@endif
                        </option>
                    @endforeach
                </x-searchable-select>
            </flux:field>

            <flux:field class="w-full sm:max-w-sm">
                <flux:label>ค้นหา</flux:label>
                <flux:input wire:model.live.debounce.300ms="positionSearch" icon="magnifying-glass" placeholder="ค้นหารหัสหรือชื่อตำแหน่ง" clearable />
            </flux:field>
        </div>
    @endif

    @if (! $selectedPositionId && config('oracle_workload.enabled') && $this->latestSyncRun)
        <div class="-mt-4 mb-6 text-xs text-zinc-500 dark:text-zinc-400">
            ซิงก์ PCM ครั้งล่าสุด {{ $this->latestSyncRun->started_at->format('d/m/Y H:i') }}
            —
            @if ($this->latestSyncRun->status === 'success')
                <span class="text-green-600 dark:text-green-400">สำเร็จ</span>
            @else
                <span class="text-red-600 dark:text-red-400">ไม่สำเร็จ</span>
            @endif
        </div>
    @endif

    @unless ($selectedPositionId)
        @if ($this->positionSummaries->isEmpty() && trim($positionSearch) === '')
            <x-empty-state icon="folder-open" message="ยังไม่มีตำแหน่ง — ซิงก์จาก PCM หรือเพิ่มตำแหน่งที่หน้าจัดการผู้ใช้ก่อน" />
        @elseif ($this->positionSummaries->isEmpty())
            <x-empty-state icon="magnifying-glass" message="ไม่พบตำแหน่งที่ค้นหา" />
        @else
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <table class="w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="hidden bg-zinc-50 lg:table-header-group dark:bg-zinc-900">
                        <tr>
                            <th class="px-4 py-3 text-left text-sm font-medium text-zinc-600 dark:text-zinc-400">ตำแหน่ง</th>
                            <th class="px-4 py-3 text-left text-sm font-medium text-zinc-600 dark:text-zinc-400">สถานะ</th>
                            <th class="px-4 py-3 text-right text-sm font-medium text-zinc-600 dark:text-zinc-400">กลุ่ม</th>
                            <th class="px-4 py-3 text-right text-sm font-medium text-zinc-600 dark:text-zinc-400">หมวด</th>
                            <th class="px-4 py-3 text-right text-sm font-medium text-zinc-600 dark:text-zinc-400">ภาระงาน</th>
                            <th class="px-4 py-3"><span class="sr-only">ดูรายละเอียด</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->positionSummaries as $row)
                            <tr
                                wire:key="pos-row-{{ $row['position']->id }}"
                                wire:click="selectPosition({{ $row['position']->id }})"
                                class="block cursor-pointer p-4 transition hover:bg-zinc-50 lg:table-row lg:p-0 dark:hover:bg-zinc-900"
                            >
                                <td class="block py-1 lg:table-cell lg:px-4 lg:py-3">
                                    <div class="text-sm font-medium">{{ $row['position']->name }}</div>
                                    <div class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ $row['position']->code }}</div>
                                </td>
                                <td class="block py-1 lg:table-cell lg:px-4 lg:py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @if (! $row['structure'] || $row['groups'] === 0)
                                            <flux:badge size="sm" color="zinc">ยังไม่มีโครงสร้าง</flux:badge>
                                        @elseif ($row['structure']->isFromOracle())
                                            <flux:badge size="sm" color="amber" icon="lock-closed">PCM</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="green">มีโครงสร้าง</flux:badge>
                                        @endif
                                        @unless ($row['position']->is_active)
                                            <flux:badge size="sm" color="red">ไม่ใช้งาน</flux:badge>
                                        @endunless
                                    </div>
                                </td>
                                <td class="flex items-center justify-between py-1 text-sm tabular-nums lg:table-cell lg:px-4 lg:py-3 lg:text-right">
                                    <span class="text-xs font-medium text-zinc-500 lg:hidden dark:text-zinc-400">กลุ่ม</span>
                                    {{ number_format($row['groups']) }}
                                </td>
                                <td class="flex items-center justify-between py-1 text-sm tabular-nums lg:table-cell lg:px-4 lg:py-3 lg:text-right">
                                    <span class="text-xs font-medium text-zinc-500 lg:hidden dark:text-zinc-400">หมวด</span>
                                    {{ number_format($row['mains']) }}
                                </td>
                                <td class="flex items-center justify-between py-1 text-sm tabular-nums lg:table-cell lg:px-4 lg:py-3 lg:text-right">
                                    <span class="text-xs font-medium text-zinc-500 lg:hidden dark:text-zinc-400">ภาระงาน</span>
                                    {{ number_format($row['workloads']) }}
                                </td>
                                <td class="block pt-2 text-right lg:table-cell lg:px-4 lg:py-3">
                                    <flux:button size="xs" variant="ghost" icon:trailing="chevron-right">ดูรายละเอียด</flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @else
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
                    @unless ($this->isLocked)
                        <div class="flex gap-1">
                            <flux:button wire:click="openCreateMain({{ $group->id }})" variant="ghost" size="xs" icon="plus">หมวด</flux:button>
                            <flux:button wire:click="cloneGroup({{ $group->id }})" variant="ghost" size="xs" icon="document-duplicate" title="สำเนา" />
                            <flux:button wire:click="openEditGroup({{ $group->id }})" variant="ghost" size="xs" icon="pencil-square" />
                            <flux:button wire:click="deleteGroup({{ $group->id }})" variant="ghost" size="xs" icon="trash" wire:confirm="ลบกลุ่มนี้และข้อมูลย่อยทั้งหมด?" />
                        </div>
                    @endunless
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
                            @unless ($this->isLocked)
                                <div class="flex gap-1">
                                    <flux:button wire:click="openCreateWorkload({{ $main->id }})" variant="ghost" size="xs" icon="plus">ภาระงาน</flux:button>
                                    <flux:button wire:click="cloneMain({{ $main->id }})" variant="ghost" size="xs" icon="document-duplicate" title="สำเนา" />
                                    <flux:button wire:click="openEditMain({{ $main->id }})" variant="ghost" size="xs" icon="pencil-square" />
                                    <flux:button wire:click="deleteMain({{ $main->id }})" variant="ghost" size="xs" icon="trash" wire:confirm="ลบหมวดนี้?" />
                                </div>
                            @endunless
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
                                    @unless ($this->isLocked)
                                        <div class="flex gap-1">
                                            <flux:button wire:click="cloneWorkload({{ $workload->id }})" variant="ghost" size="xs" icon="document-duplicate" title="สำเนา" />
                                            <flux:button wire:click="openEditWorkload({{ $workload->id }})" variant="ghost" size="xs" icon="pencil-square" />
                                            <flux:button wire:click="deleteWorkload({{ $workload->id }})" variant="ghost" size="xs" icon="trash" wire:confirm="ลบภาระงานนี้?" />
                                        </div>
                                    @endunless
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @empty
            <x-empty-state icon="folder-open" message="ยังไม่มีโครงสร้างภาระงานของตำแหน่งนี้ในปีงบประมาณนี้">
                @unless ($this->isLocked)
                    <div class="flex flex-wrap justify-center gap-2">
                        <flux:button wire:click="openCreateGroup" variant="primary">เพิ่มกลุ่มแรก</flux:button>
                        <flux:button wire:click="openImportModal" variant="ghost" icon="arrow-up-tray">นำเข้าจาก PCM</flux:button>
                    </div>
                @endunless
            </x-empty-state>
        @endforelse
    </div>
    @endunless

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
                ตำแหน่ง <strong>{{ $this->selectedPosition?->label() }}</strong>
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
                    @if ($importDetectedPosition)
                        <p class="mt-1 text-zinc-600 dark:text-zinc-400">
                            ตำแหน่งในไฟล์: {{ $importDetectedPosition['code'] }} {{ $importDetectedPosition['name'] }}
                        </p>
                    @endif
                </div>
                @if ($importDetectedPosition && $importDetectedPosition['code'] !== $this->selectedPosition?->code)
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        ตำแหน่งในไฟล์ ({{ $importDetectedPosition['code'] }}) ไม่ตรงกับตำแหน่งที่เลือก ({{ $this->selectedPosition?->code }}) — ตรวจสอบก่อนนำเข้า
                    </flux:callout>
                @endif
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

    <flux:modal wire:model="showCopyPositionModal" class="md:w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">คัดลอกไปยังตำแหน่งอื่น</flux:heading>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                คัดลอกโครงสร้างของตำแหน่ง <strong>{{ $this->selectedPosition?->label() }}</strong>
                ปี <strong>{{ $this->selectedFiscalYear?->year }}</strong> ไปยังตำแหน่งปลายทางในปีเดียวกัน
            </p>
            <flux:field>
                <flux:label>ตำแหน่งปลายทาง</flux:label>
                <x-searchable-select wire:model="copyTargetPositionId">
                    <option value="">— เลือกตำแหน่ง —</option>
                    @foreach ($this->positions as $position)
                        @continue($position->id === $selectedPositionId)
                        <option wire:key="pos-copy-{{ $position->id }}" value="{{ $position->id }}">{{ $position->label() }}</option>
                    @endforeach
                </x-searchable-select>
                <flux:error name="copyTargetPositionId" />
            </flux:field>
            <flux:checkbox wire:model="replaceExistingOnCopyPosition" label="แทนที่โครงสร้างเดิมของตำแหน่งปลายทาง (ถ้ามีอยู่แล้ว)" />
            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showCopyPositionModal', false)" variant="ghost">ยกเลิก</flux:button>
                <flux:button wire:click="copyToPosition" variant="primary" wire:loading.attr="disabled">คัดลอก</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="showCopyModal" class="md:w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">คัดลอกไปยังปีงบประมาณหน้า</flux:heading>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                ระบบจะคัดลอกโครงสร้างภาระงานของทุกตำแหน่งและรอบประเมินจากปี
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
