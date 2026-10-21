<?php

use App\Actions\EnableAllWorkloadShares;
use App\Actions\SyncCollaboratorWorkEntries;
use App\Data\WorkRecordScoreSummary;
use App\Enums\UserRole;
use App\Enums\WorkRecordStatus;
use App\Models\EvaluationPeriod;
use App\Models\User;
use App\Models\WorkEntry;
use App\Models\WorkEntryEvidence;
use App\Models\WorkRecord;
use App\Models\WorkloadGroup;
use App\Models\WorkloadShare;
use App\Models\WorkloadStructure;
use App\Rules\RichTextMinLength;
use App\Support\WorkloadStructureNumbering;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public int $step = 1;

    public ?WorkRecord $workRecord = null;

    public ?int $selectedPeriodId = null;

    /** @var array<int, array<int, array<string, mixed>>> */
    public array $entries = [];

    public bool $readOnly = false;

    public bool $entryModalOpen = false;

    public bool $deleteEntryConfirmOpen = false;

    public bool $modalReadOnly = false;

    public ?int $modalWorkloadId = null;

    public ?int $modalEntryIndex = null;

    /** @var array<string, mixed> */
    public array $modalEntry = [
        'id' => null,
        'detail' => '',
        'result' => '',
        'hours' => '',
        'evidence_type' => 'url',
        'evidence' => '',
        'evidence_files' => [],
        'collaborator_ids' => [],
    ];

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $evidenceUploads = [];

    public ?int $selectedWorkloadId = null;

    public string $workloadSearch = '';

    public string $collaboratorSearch = '';

    public string $workloadFilter = 'all';

    public bool $sidebarDrawerOpen = false;

    public bool $withdrawConfirmOpen = false;

    public function mount(?WorkRecord $record = null): void
    {
        if ($record?->exists) {
            Gate::authorize('view', $record);

            $this->workRecord = $record->load([
                'evaluationPeriod.fiscalYear',
                'workEntries.evidences',
                'workEntries.mirroredEntries.workRecord.user',
                'workEntries.sourceEntry.workRecord.user',
            ]);

            $this->selectedPeriodId = $record->evaluation_period_id;
            $this->readOnly = ! $record->isEditable() || ! Gate::allows('update', $record);
            $this->loadEntriesFromRecord();

            if ($this->readOnly) {
                $this->step = 3;
            } else {
                $this->step = 2;
                $this->ensureSelectedWorkload();
            }

            return;
        }

        $this->readOnly = false;
        $this->step = 1;
    }

    #[Computed]
    public function canWithdraw(): bool
    {
        return $this->workRecord !== null
            && $this->readOnly
            && Gate::allows('withdraw', $this->workRecord);
    }

    #[Computed]
    public function withdrawUnavailableMessage(): ?string
    {
        if (! $this->workRecord || ! $this->readOnly || $this->workRecord->user_id !== Auth::id()) {
            return null;
        }

        if (Gate::allows('withdraw', $this->workRecord)) {
            return null;
        }

        return match ($this->workRecord->status) {
            WorkRecordStatus::Approved => 'บันทึกอนุมัติแล้ว ไม่สามารถถอนการส่งได้',
            WorkRecordStatus::Submitted => 'รอบประเมินปิดแล้ว ไม่สามารถถอนการส่งได้',
            default => null,
        };
    }

    #[Computed]
    public function collaboratorSearchResults()
    {
        if (mb_strlen(trim($this->collaboratorSearch)) < 2) {
            return collect();
        }

        $term = trim($this->collaboratorSearch);
        $selected = $this->modalEntry['collaborator_ids'] ?? [];

        return User::query()
            ->where('role', UserRole::Staff)
            ->where('id', '!=', Auth::id())
            ->when($this->structurePositionId, fn ($query, int $positionId) => $query->where('position_id', $positionId))
            ->when($selected !== [], fn ($query) => $query->whereNotIn('id', $selected))
            ->where(fn ($query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%"))
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'department']);
    }

    #[Computed]
    public function frequentCollaborators()
    {
        $selected = $this->modalEntry['collaborator_ids'] ?? [];

        $ownEntryIds = WorkEntry::query()
            ->select('work_entries.id')
            ->join('work_records as own', 'own.id', '=', 'work_entries.work_record_id')
            ->where('own.user_id', Auth::id());

        return User::query()
            ->select('users.id', 'users.name', 'users.department')
            ->selectRaw('COUNT(*) as uses')
            ->join('work_records', 'work_records.user_id', '=', 'users.id')
            ->join('work_entries as mirror', 'mirror.work_record_id', '=', 'work_records.id')
            ->whereIn('mirror.source_work_entry_id', $ownEntryIds)
            ->where('users.role', UserRole::Staff)
            ->when($this->structurePositionId, fn ($query, int $positionId) => $query->where('users.position_id', $positionId))
            ->when($selected !== [], fn ($query) => $query->whereNotIn('users.id', $selected))
            ->groupBy('users.id', 'users.name', 'users.department')
            ->orderByDesc('uses')
            ->orderBy('users.name')
            ->limit(6)
            ->get();
    }

    #[Computed]
    public function modalCollaborators()
    {
        $ids = $this->modalEntry['collaborator_ids'] ?? [];

        if ($ids === []) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name', 'department']);
    }

    public function addCollaborator(int $userId): void
    {
        if ($this->readOnly || $this->modalReadOnly) {
            return;
        }

        $ids = $this->modalEntry['collaborator_ids'] ?? [];

        if (! in_array($userId, $ids, true)) {
            $ids[] = $userId;
        }

        $this->modalEntry['collaborator_ids'] = $ids;
        $this->collaboratorSearch = '';
        unset($this->collaboratorSearchResults, $this->modalCollaborators, $this->frequentCollaborators);
    }

    public function removeCollaborator(int $userId): void
    {
        if ($this->readOnly || $this->modalReadOnly) {
            return;
        }

        $this->modalEntry['collaborator_ids'] = array_values(array_filter(
            $this->modalEntry['collaborator_ids'] ?? [],
            fn ($id) => (int) $id !== $userId,
        ));

        unset($this->modalCollaborators, $this->collaboratorSearchResults, $this->frequentCollaborators);
    }

    #[Computed]
    public function openPeriods()
    {
        return EvaluationPeriod::query()
            ->with('fiscalYear')
            ->open()
            ->orderByDesc('start_date')
            ->get();
    }

    #[Computed]
    public function selectedPeriod()
    {
        if (! $this->selectedPeriodId) {
            return null;
        }

        return EvaluationPeriod::with('fiscalYear')->find($this->selectedPeriodId);
    }

    /**
     * The record's snapshotted structure, or the one the current user's position
     * resolves to for the selected period's fiscal year.
     */
    #[Computed]
    public function structureId(): ?int
    {
        if ($this->workRecord?->workload_structure_id) {
            return $this->workRecord->workload_structure_id;
        }

        $period = $this->selectedPeriod;

        if (! $period) {
            return null;
        }

        return WorkloadStructure::forUserAndFiscalYear(Auth::user(), $period->fiscal_year_id)?->id;
    }

    #[Computed]
    public function structurePositionId(): ?int
    {
        return $this->structureId
            ? WorkloadStructure::whereKey($this->structureId)->value('position_id')
            : null;
    }

    #[Computed]
    public function workloadGroups()
    {
        if (! $this->structureId) {
            return collect();
        }

        $groups = WorkloadGroup::query()
            ->forStructure($this->structureId)
            ->with(['workloadMains.workloads'])
            ->get();

        // Hide items deactivated by an Oracle sync, unless this record already has entries on them.
        $usedWorkloadIds = array_map('intval', array_keys(array_filter($this->entries)));

        return $groups
            ->filter(fn (WorkloadGroup $group) => $group->is_active || $group->workloadMains->contains(
                fn ($main) => $main->workloads->whereIn('id', $usedWorkloadIds)->isNotEmpty(),
            ))
            ->each(function (WorkloadGroup $group) use ($usedWorkloadIds) {
                $group->setRelation('workloadMains', $group->workloadMains
                    ->each(fn ($main) => $main->setRelation('workloads', $main->workloads
                        ->filter(fn ($workload) => $workload->is_active || in_array($workload->id, $usedWorkloadIds, true))
                        ->values()))
                    ->filter(fn ($main) => $main->is_active || $main->workloads->isNotEmpty())
                    ->values());
            })
            ->values();
    }

    #[Computed]
    public function structureMissingMessage(): ?string
    {
        if (! $this->selectedPeriodId || $this->structureId) {
            return null;
        }

        return Auth::user()->position_id === null
            ? 'ยังไม่ได้กำหนดตำแหน่งของคุณ กรุณาติดต่อผู้ดูแลระบบ'
            : 'ยังไม่มีโครงสร้างภาระงานสำหรับตำแหน่งของคุณในปีงบประมาณนี้ กรุณาติดต่อผู้ดูแลระบบ';
    }

    #[Computed]
    public function totalEntriesCount(): int
    {
        return collect($this->entries)->flatten(1)->count();
    }

    #[Computed]
    public function totalHoursSum(): float
    {
        return (float) collect($this->entries)
            ->flatten(1)
            ->sum(fn (array $entry) => (float) ($entry['hours'] ?? 0));
    }

    #[Computed]
    public function structureNumbers(): array
    {
        return WorkloadStructureNumbering::buildMap($this->workloadGroups);
    }

    #[Computed]
    public function flatWorkloads()
    {
        $numbers = $this->structureNumbers;
        $items = collect();

        foreach ($this->workloadGroups as $group) {
            foreach ($group->workloadMains as $main) {
                foreach ($main->workloads as $workload) {
                    $workloadEntries = $this->entries[$workload->id] ?? [];

                    $unitScore = $workload->score !== null ? (float) $workload->score : null;
                    $earnedScore = $unitScore !== null
                        ? (float) collect($workloadEntries)->sum(
                            fn (array $e) => $unitScore * (float) ($e['hours'] ?? 0),
                        )
                        : 0.0;

                    $items->push([
                        'id' => $workload->id,
                        'name' => $workload->name,
                        'number' => $numbers['workloads'][$workload->id] ?? '',
                        'group_number' => $numbers['groups'][$group->id] ?? '',
                        'main_number' => $numbers['mains'][$main->id] ?? '',
                        'score' => $workload->score,
                        'earned_score' => $earnedScore,
                        'group_id' => $group->id,
                        'group_name' => $group->name,
                        'group_max_score' => $group->max_score,
                        'main_id' => $main->id,
                        'main_name' => $main->name,
                        'main_max_score' => $main->max_score,
                        'entry_count' => count($workloadEntries),
                        'hours_sum' => (float) collect($workloadEntries)->sum(fn (array $e) => (float) ($e['hours'] ?? 0)),
                    ]);
                }
            }
        }

        return $items;
    }

    #[Computed]
    public function filteredWorkloads()
    {
        $search = mb_strtolower(trim($this->workloadSearch));

        return $this->flatWorkloads->filter(function (array $item) use ($search) {
            if ($this->workloadFilter === 'filled' && $item['entry_count'] === 0) {
                return false;
            }

            if ($this->workloadFilter === 'empty' && $item['entry_count'] > 0) {
                return false;
            }

            if ($search === '') {
                return true;
            }

            $haystack = mb_strtolower(
                $item['number'].' '.$item['group_number'].' '.$item['main_number'].' '
                .$item['name'].' '.$item['group_name'].' '.$item['main_name'],
            );

            return str_contains($haystack, $search);
        })->values();
    }

    #[Computed]
    public function filteredWorkloadTree()
    {
        $filteredIds = $this->filteredWorkloads->pluck('id')->flip();
        $flatById = $this->filteredWorkloads->keyBy('id');
        $tree = collect();

        foreach ($this->workloadGroups as $group) {
            $mains = collect();

            foreach ($group->workloadMains as $main) {
                $workloads = collect();

                foreach ($main->workloads as $workload) {
                    if ($filteredIds->has($workload->id)) {
                        $workloads->push($flatById->get($workload->id));
                    }
                }

                if ($workloads->isNotEmpty()) {
                    $mains->push([
                        'main' => $main,
                        'workloads' => $workloads,
                    ]);
                }
            }

            if ($mains->isNotEmpty()) {
                $tree->push([
                    'group' => $group,
                    'mains' => $mains,
                ]);
            }
        }

        return $tree;
    }

    #[Computed]
    public function categoryTotals(): array
    {
        $mains = [];

        foreach ($this->flatWorkloads->groupBy('main_id') as $mainId => $items) {
            $total = (float) $items->sum('earned_score');
            $max = $items->first()['main_max_score'] ?? null;

            $mains[$mainId] = [
                'entry_count' => (int) $items->sum('entry_count'),
                'score' => $max !== null ? min($total, (float) $max) : $total,
                'max_score' => $max !== null ? (float) $max : null,
            ];
        }

        $groups = [];

        foreach ($this->flatWorkloads->groupBy('group_id') as $groupId => $items) {
            $total = (float) $items
                ->pluck('main_id')
                ->unique()
                ->sum(fn (int $mainId) => $mains[$mainId]['score'] ?? 0.0);
            $max = $items->first()['group_max_score'] ?? null;

            $groups[$groupId] = [
                'entry_count' => (int) $items->sum('entry_count'),
                'score' => $max !== null ? min($total, (float) $max) : $total,
                'max_score' => $max !== null ? (float) $max : null,
            ];
        }

        return ['groups' => $groups, 'mains' => $mains];
    }

    #[Computed]
    public function groupSummaries(): array
    {
        $totals = $this->categoryTotals['groups'];

        return $this->flatWorkloads
            ->groupBy('group_id')
            ->map(function ($items, $groupId) use ($totals) {
                $first = $items->first();

                return [
                    'id' => $groupId,
                    'label' => trim(($first['group_number'] ?? '').' '.($first['group_name'] ?? '')),
                    'workloads_total' => $items->count(),
                    'workloads_filled' => $items->where('entry_count', '>', 0)->count(),
                    'entry_count' => $totals[$groupId]['entry_count'] ?? 0,
                    'hours' => (float) $items->sum('hours_sum'),
                    'score' => $totals[$groupId]['score'] ?? 0.0,
                    'max_score' => $totals[$groupId]['max_score'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    #[Computed]
    public function workloadProgress(): array
    {
        $total = $this->flatWorkloads->count();
        $filled = $this->flatWorkloads->where('entry_count', '>', 0)->count();
        $percent = $total > 0 ? (int) round(($filled / $total) * 100) : 0;

        return [
            'total' => $total,
            'filled' => $filled,
            'percent' => $percent,
        ];
    }

    #[Computed]
    public function activeWorkload(): ?array
    {
        if (! $this->selectedWorkloadId) {
            return null;
        }

        return $this->flatWorkloads->firstWhere('id', $this->selectedWorkloadId);
    }

    #[Computed]
    public function liveScoreSummary(): WorkRecordScoreSummary
    {
        $mainTotals = $this->flatWorkloads
            ->groupBy('main_id')
            ->map(function ($items) {
                $total = (float) $items->sum('earned_score');
                $max = $items->first()['main_max_score'] ?? null;

                return $max !== null ? min($total, (float) $max) : $total;
            });

        $earned = 0.0;

        foreach ($this->flatWorkloads->groupBy('group_id') as $groupId => $items) {
            $groupTotal = (float) $items
                ->pluck('main_id')
                ->unique()
                ->sum(fn (int $mainId) => $mainTotals[$mainId] ?? 0.0);

            $max = $items->first()['group_max_score'] ?? null;

            if ($max !== null) {
                $groupTotal = min($groupTotal, (float) $max);
            }

            $earned += $groupTotal;
        }

        $maximumSum = (float) $this->workloadGroups
            ->whereNotNull('max_score')
            ->sum('max_score');

        return new WorkRecordScoreSummary(
            earned: $earned,
            maximum: $maximumSum > 0 ? $maximumSum : null,
        );
    }

    #[Computed]
    public function step3ChartData(): array
    {
        $hoursByGroup = [];
        $hoursRadar = [];

        foreach ($this->flatWorkloads->groupBy('group_id') as $items) {
            $first = $items->first();
            $label = trim(($first['group_number'] ?? '').' '.($first['group_name'] ?? ''));

            $hours = (float) $items->sum('hours_sum');

            $hoursRadar[] = [
                'label' => $label,
                'hours' => $hours,
            ];

            if ($hours > 0) {
                $hoursByGroup[] = [
                    'label' => $label,
                    'hours' => $hours,
                ];
            }
        }

        $progress = $this->workloadProgress;

        return [
            'hasEntries' => $this->totalEntriesCount > 0,
            'hoursByGroup' => [
                'labels' => array_column($hoursByGroup, 'label'),
                'values' => array_column($hoursByGroup, 'hours'),
            ],
            'hoursRadar' => [
                'labels' => array_column($hoursRadar, 'label'),
                'values' => array_column($hoursRadar, 'hours'),
            ],
            'progress' => [
                'filled' => $progress['filled'],
                'empty' => max(0, $progress['total'] - $progress['filled']),
                'percent' => $progress['percent'],
            ],
        ];
    }

    public function selectPeriod(int $periodId): void
    {
        if ($this->readOnly) {
            return;
        }

        $this->selectedPeriodId = $periodId;
        $this->selectedWorkloadId = null;
        unset($this->selectedPeriod, $this->structureId, $this->structurePositionId, $this->structureMissingMessage, $this->workloadGroups);
    }

    public function goToStep(int $step): void
    {
        if ($step === 2 && ! $this->selectedPeriodId) {
            Flux::toast('กรุณาเลือกรอบประเมิน', variant: 'danger');

            return;
        }

        if ($step === 2 && ! $this->structureId) {
            Flux::toast($this->structureMissingMessage ?? 'ไม่พบโครงสร้างภาระงาน', variant: 'danger');

            return;
        }

        $this->step = $step;

        if ($step === 2) {
            $this->ensureSelectedWorkload();
        }
    }

    public function selectWorkload(int $id): void
    {
        $this->selectedWorkloadId = $id;
        $this->sidebarDrawerOpen = false;
        unset($this->selectedWorkloadShare);
    }

    #[Computed]
    public function canShareSelectedWorkload(): bool
    {
        if (! $this->workRecord || ! $this->selectedWorkloadId) {
            return false;
        }

        return Gate::allows('manageRecordWorkload', [$this->workRecord, $this->selectedWorkloadId]);
    }

    #[Computed]
    public function selectedWorkloadShare(): ?WorkloadShare
    {
        if (! $this->workRecord || ! $this->selectedWorkloadId) {
            return null;
        }

        return WorkloadShare::query()
            ->where('work_record_id', $this->workRecord->id)
            ->where('workload_id', $this->selectedWorkloadId)
            ->first();
    }

    public function enableWorkloadShare(): void
    {
        if (! $this->workRecord || ! $this->selectedWorkloadId) {
            return;
        }

        Gate::authorize('manageRecordWorkload', [$this->workRecord, $this->selectedWorkloadId]);

        $share = WorkloadShare::query()->firstOrNew([
            'work_record_id' => $this->workRecord->id,
            'workload_id' => $this->selectedWorkloadId,
        ]);

        $share->regenerateToken();
        $share->enabled_at = now();
        $share->save();

        unset($this->selectedWorkloadShare);

        Flux::toast('เปิดการแชร์ลิงก์สรุปแล้ว');
    }

    public function disableWorkloadShare(): void
    {
        $share = $this->selectedWorkloadShare;

        if ($share === null) {
            return;
        }

        Gate::authorize('manage', $share);

        $share->update(['enabled_at' => null]);

        unset($this->selectedWorkloadShare);

        Flux::toast('ปิดการแชร์ลิงก์แล้ว');
    }

    public function notifyShareLinkCopied(): void
    {
        Flux::toast('คัดลอกลิงก์แล้ว');
    }

    #[Computed]
    public function canManageShareLinks(): bool
    {
        return $this->workRecord !== null
            && $this->workRecord->user_id === Auth::id()
            && in_array($this->workRecord->status, [WorkRecordStatus::Submitted, WorkRecordStatus::Approved], true);
    }

    #[Computed]
    public function sharedWorkloadLinks()
    {
        if (! $this->canManageShareLinks) {
            return collect();
        }

        $shares = WorkloadShare::query()
            ->where('work_record_id', $this->workRecord->id)
            ->whereNotNull('enabled_at')
            ->get()
            ->keyBy('workload_id');

        return $this->flatWorkloads
            ->filter(fn (array $workload) => $shares->has($workload['id']))
            ->map(fn (array $workload) => [
                'workload_id' => $workload['id'],
                'number' => $workload['number'],
                'name' => $workload['name'],
                'group' => trim($workload['group_number'].' '.$workload['group_name']),
                'main' => trim($workload['main_number'].' '.$workload['main_name']),
                'url' => $shares[$workload['id']]->shareUrl(),
            ])
            ->values();
    }

    public function enableAllWorkloadShares(): void
    {
        if (! $this->canManageShareLinks) {
            return;
        }

        app(EnableAllWorkloadShares::class)($this->workRecord);

        unset($this->sharedWorkloadLinks, $this->selectedWorkloadShare);

        Flux::toast('สร้างลิงก์แชร์ทุกภาระงานแล้ว');
    }

    public function setWorkloadFilter(string $filter): void
    {
        if (! in_array($filter, ['all', 'filled', 'empty'], true)) {
            return;
        }

        $this->workloadFilter = $filter;
        $this->ensureSelectedWorkload();
    }

    public function reviewEmptyWorkloads(): void
    {
        $this->workloadFilter = 'empty';
        $this->goToStep(2);
    }

    public function updatedWorkloadSearch(): void
    {
        $this->ensureSelectedWorkload();
    }

    public function updatedWorkloadFilter(): void
    {
        $this->ensureSelectedWorkload();
    }

    protected function ensureSelectedWorkload(): void
    {
        unset(
            $this->flatWorkloads,
            $this->filteredWorkloads,
            $this->filteredWorkloadTree,
            $this->workloadProgress,
            $this->activeWorkload,
            $this->liveScoreSummary,
        );

        $filtered = $this->filteredWorkloads;

        if ($filtered->isEmpty()) {
            $this->selectedWorkloadId = null;

            return;
        }

        if ($this->selectedWorkloadId && $filtered->contains('id', $this->selectedWorkloadId)) {
            return;
        }

        $withEntries = $filtered->firstWhere('entry_count', '>', 0);
        $this->selectedWorkloadId = ($withEntries ?? $filtered->first())['id'];
    }

    protected function bustWorkloadComputeds(): void
    {
        unset(
            $this->flatWorkloads,
            $this->filteredWorkloads,
            $this->filteredWorkloadTree,
            $this->workloadProgress,
            $this->activeWorkload,
            $this->liveScoreSummary,
        );
    }

    public function saveDraft(bool $showToast = true): void
    {
        if ($this->readOnly) {
            return;
        }

        $this->validate([
            'selectedPeriodId' => ['required', 'exists:evaluation_periods,id'],
        ]);

        if (! $this->workRecord) {
            $existing = WorkRecord::query()
                ->where('user_id', Auth::id())
                ->where('evaluation_period_id', $this->selectedPeriodId)
                ->first();

            if ($existing) {
                $this->workRecord = $existing;
                $this->loadEntriesFromRecord();
            } else {
                if (! $this->structureId) {
                    Flux::toast($this->structureMissingMessage ?? 'ไม่พบโครงสร้างภาระงาน', variant: 'danger');

                    return;
                }

                $this->workRecord = WorkRecord::create([
                    'user_id' => Auth::id(),
                    'evaluation_period_id' => $this->selectedPeriodId,
                    'workload_structure_id' => $this->structureId,
                    'status' => WorkRecordStatus::Draft,
                ]);
            }
        }

        Gate::authorize('update', $this->workRecord);

        $this->syncEntriesToDatabase();

        if ($showToast) {
            Flux::toast('บันทึกฉบับร่างแล้ว');
        }
    }

    public function submit(): void
    {
        if ($this->readOnly) {
            return;
        }

        $this->saveDraft(showToast: false);

        if (! $this->workRecord) {
            return;
        }

        Gate::authorize('submit', $this->workRecord);

        if ($this->totalEntriesCount === 0) {
            Flux::toast('กรุณาบันทึกงานอย่างน้อย 1 รายการ', variant: 'danger');

            return;
        }

        $this->workRecord->update([
            'status' => WorkRecordStatus::Submitted,
            'submitted_at' => now(),
            'rejection_comment' => null,
            'approved_at' => null,
        ]);

        app(EnableAllWorkloadShares::class)($this->workRecord);

        Flux::toast('ส่งบันทึกและสร้างลิงก์แชร์ทุกภาระงานแล้ว');

        $this->redirect(route('staff.records.show', $this->workRecord), navigate: true);
    }

    public function openWithdrawConfirm(): void
    {
        if (! $this->workRecord || ! Gate::allows('withdraw', $this->workRecord)) {
            return;
        }

        $this->withdrawConfirmOpen = true;
    }

    public function cancelWithdrawConfirm(): void
    {
        $this->withdrawConfirmOpen = false;
    }

    public function confirmWithdraw(): void
    {
        $this->cancelWithdrawConfirm();
        $this->withdraw();
    }

    public function withdraw(): void
    {
        if (! $this->workRecord) {
            return;
        }

        Gate::authorize('withdraw', $this->workRecord);

        $this->workRecord->update([
            'status' => WorkRecordStatus::Draft,
            'submitted_at' => null,
        ]);

        Flux::toast('ถอนการส่งแล้ว สามารถแก้ไขและส่งใหม่ได้');

        $this->redirect(route('staff.records.edit', $this->workRecord), navigate: true);
    }

    public function openEntryModal(int $workloadId, ?int $index = null): void
    {
        $this->selectedWorkloadId = $workloadId;
        $this->modalWorkloadId = $workloadId;
        $this->modalEntryIndex = $index;
        $this->evidenceUploads = [];
        $this->collaboratorSearch = '';

        if ($index !== null && isset($this->entries[$workloadId][$index])) {
            $this->modalEntry = $this->copyEntryForModal($this->entries[$workloadId][$index]);
            $this->modalReadOnly = $this->readOnly || ! empty($this->entries[$workloadId][$index]['is_mirror']);
        } else {
            $this->modalEntry = [
                'id' => null,
                'detail' => '',
                'result' => '',
                'hours' => '',
                'evidence_type' => 'url',
                'evidence' => '',
                'evidence_files' => [],
                'collaborator_ids' => [],
                'is_mirror' => false,
            ];
            $this->modalReadOnly = $this->readOnly;
        }

        $this->entryModalOpen = true;
    }

    public function openDeleteEntryConfirm(): void
    {
        if ($this->readOnly || $this->modalReadOnly || $this->modalEntryIndex === null || ! $this->modalWorkloadId) {
            return;
        }

        $this->deleteEntryConfirmOpen = true;
    }

    public function cancelDeleteEntryConfirm(): void
    {
        $this->deleteEntryConfirmOpen = false;
    }

    public function confirmDeleteModalEntry(): void
    {
        $this->cancelDeleteEntryConfirm();
        $this->deleteModalEntry();
    }

    public function closeEntryModal(bool $discardPendingUploads = true): void
    {
        if ($discardPendingUploads && ! $this->readOnly) {
            foreach ($this->entryEvidenceFiles($this->modalEntry) as $file) {
                if (blank($file['id'] ?? null) && filled($path = $file['path'] ?? null)) {
                    Storage::disk('public')->delete($path);
                }
            }
        }

        $this->entryModalOpen = false;
        $this->deleteEntryConfirmOpen = false;
        $this->modalWorkloadId = null;
        $this->modalEntryIndex = null;
        $this->modalReadOnly = false;
        $this->collaboratorSearch = '';
        $this->modalEntry = [
            'id' => null,
            'detail' => '',
            'result' => '',
            'hours' => '',
            'evidence_type' => 'url',
            'evidence' => '',
            'evidence_files' => [],
            'collaborator_ids' => [],
        ];
        $this->evidenceUploads = [];
        $this->resetValidation();
    }

    public function setEvidenceType(string $type): void
    {
        if (! in_array($type, ['url', 'file'], true)) {
            return;
        }

        $previousType = $this->modalEntry['evidence_type'] ?? 'url';

        if ($type === $previousType) {
            return;
        }

        if ($type === 'file' && $previousType === 'url') {
            $this->modalEntry['evidence_files'] = [];
        }

        if ($type === 'url' && $previousType === 'file') {
            $this->deleteStoredEvidence($this->modalEntry);
            $this->modalEntry['evidence'] = '';
            $this->modalEntry['evidence_files'] = [];
            $this->evidenceUploads = [];
        }

        $this->modalEntry['evidence_type'] = $type;
        $this->resetValidation(['modalEntry.evidence', 'evidenceUploads', 'evidenceUploads.*']);
    }

    public function removeEvidenceUpload(int $index): void
    {
        unset($this->evidenceUploads[$index]);
        $this->evidenceUploads = array_values($this->evidenceUploads);
        $this->resetValidation(['evidenceUploads', 'evidenceUploads.*']);
    }

    public function removeStoredEvidenceFile(int $index): void
    {
        $files = $this->modalEvidenceFiles();

        if (! isset($files[$index])) {
            return;
        }

        $file = $files[$index];

        if ($id = $file['id'] ?? null) {
            WorkEntryEvidence::find($id)?->delete();
        } elseif ($path = $file['path'] ?? null) {
            Storage::disk('public')->delete($path);
        }

        unset($files[$index]);
        $this->modalEntry['evidence_files'] = array_values($files);
    }

    public function updatedEvidenceUploads(): void
    {
        if ($this->readOnly || ($this->modalEntry['evidence_type'] ?? 'url') !== 'file' || $this->evidenceUploads === []) {
            return;
        }

        $this->validate(['evidenceUploads.*' => $this->evidenceUploadRules()]);

        if (! $this->workRecord && $this->selectedPeriodId) {
            $this->saveDraft(showToast: false);
        }

        $this->persistPendingEvidenceUploads();
    }

    /**
     * @return list<string>
     */
    protected function evidenceUploadRules(): array
    {
        return [
            'file',
            'max:10240',
            'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp',
        ];
    }

    protected function persistPendingEvidenceUploads(): void
    {
        if ($this->evidenceUploads === [] || ! $this->workRecord) {
            return;
        }

        $stored = $this->modalEvidenceFiles();

        foreach ($this->evidenceUploads as $upload) {
            $stored[] = [
                'id' => null,
                'path' => $upload->store(
                    'work-evidence/'.$this->workRecord->id,
                    'public',
                ),
                'filename' => $upload->getClientOriginalName(),
            ];
        }

        $this->modalEntry['evidence_files'] = array_values($stored);
        $this->evidenceUploads = [];
    }

    public function saveModalEntry(): void
    {
        if ($this->readOnly || $this->modalReadOnly) {
            $this->closeEntryModal();

            return;
        }

        $rules = [
            'modalEntry.detail' => ['required', 'string', new RichTextMinLength(10)],
            'modalEntry.hours' => ['nullable', 'numeric', 'min:1', 'max:999'],
            'modalEntry.collaborator_ids' => ['nullable', 'array'],
            'modalEntry.collaborator_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Staff->value),
                Rule::notIn([Auth::id()]),
            ],
        ];

        if (($this->modalEntry['evidence_type'] ?? 'url') === 'url') {
            $rules['modalEntry.evidence'] = ['nullable', 'url', 'max:2048'];
        } else {
            $hasExistingFiles = ($this->modalEntry['evidence_type'] ?? 'url') === 'file'
                && $this->modalEvidenceFiles() !== [];

            $rules['evidenceUploads'] = [$hasExistingFiles || $this->evidenceUploads !== [] ? 'nullable' : 'required', 'array'];
            $rules['evidenceUploads.*'] = $this->evidenceUploadRules();
        }

        $this->validate($rules);

        if (! $this->workRecord && $this->selectedPeriodId) {
            $this->saveDraft(showToast: false);
        }

        $workloadId = $this->modalWorkloadId;
        $this->entries[$workloadId] ??= [];

        $entryData = $this->modalEntry;

        if (($entryData['evidence_type'] ?? 'url') === 'file' && $this->workRecord) {
            $this->persistPendingEvidenceUploads();
            $entryData['evidence_files'] = $this->modalEvidenceFiles();
            $entryData['evidence_type'] = 'file';
        } elseif (($entryData['evidence_type'] ?? 'url') === 'url') {
            $this->deleteStoredEvidence($entryData);
            $entryData['evidence'] = trim($entryData['evidence'] ?? '');
            $entryData['evidence_files'] = [];
            $entryData['evidence_type'] = filled($entryData['evidence']) ? 'url' : ($entryData['evidence_type'] ?? 'url');
        } elseif (($entryData['evidence_type'] ?? 'url') === 'file') {
            $entryData['evidence_type'] = 'file';
            $entryData['evidence_files'] = $this->modalEvidenceFiles();
        }

        if ($this->modalEntryIndex !== null) {
            $this->entries[$workloadId][$this->modalEntryIndex] = $entryData;
        } else {
            $this->entries[$workloadId][] = $entryData;
        }

        $this->syncEntriesToDatabase();
        $this->bustWorkloadComputeds();
        $this->selectedWorkloadId = $workloadId;

        Flux::toast('บันทึกผลงานแล้ว');
        $this->closeEntryModal(discardPendingUploads: false);
    }

    public function deleteModalEntry(): void
    {
        if ($this->readOnly || $this->modalReadOnly || $this->modalEntryIndex === null || ! $this->modalWorkloadId) {
            return;
        }

        $workloadId = (int) $this->modalWorkloadId;
        $index = (int) $this->modalEntryIndex;

        if (! isset($this->entries[$workloadId][$index]) && ($entryId = $this->modalEntry['id'] ?? null)) {
            foreach ($this->entries[$workloadId] ?? [] as $i => $entry) {
                if (($entry['id'] ?? null) == $entryId) {
                    $index = $i;
                    break;
                }
            }
        }

        if (! isset($this->entries[$workloadId][$index])) {
            Flux::toast('ไม่พบผลงานที่จะลบ', variant: 'danger');

            return;
        }

        $entry = $this->entries[$workloadId][$index];

        if (! empty($entry['is_mirror'])) {
            Flux::toast('ไม่สามารถลบผลงานร่วมได้', variant: 'danger');

            return;
        }

        if ($entryId = $entry['id'] ?? null) {
            WorkEntry::find($entryId)?->delete();
        } else {
            $this->deleteStoredEvidence($entry);
        }

        unset($this->entries[$workloadId][$index]);
        $this->entries[$workloadId] = array_values($this->entries[$workloadId]);

        if ($this->workRecord) {
            $this->syncEntriesToDatabase();
        }

        $this->bustWorkloadComputeds();
        Flux::toast('ลบผลงานแล้ว');
        $this->closeEntryModal();
    }

    public function deleteEntry(int $workloadId, int $index): void
    {
        if ($this->readOnly) {
            return;
        }

        if (! isset($this->entries[$workloadId][$index])) {
            return;
        }

        if (! empty($this->entries[$workloadId][$index]['is_mirror'])) {
            Flux::toast('ไม่สามารถลบผลงานร่วมได้', variant: 'danger');

            return;
        }

        if ($entryId = $this->entries[$workloadId][$index]['id'] ?? null) {
            WorkEntry::find($entryId)?->delete();
        } else {
            $this->deleteStoredEvidence($this->entries[$workloadId][$index]);
        }

        unset($this->entries[$workloadId][$index]);
        $this->entries[$workloadId] = array_values($this->entries[$workloadId] ?? []);

        $this->saveDraft(showToast: false);
        Flux::toast('ลบผลงานแล้ว');
    }

    protected function loadEntriesFromRecord(): void
    {
        $this->entries = [];

        foreach ($this->workRecord->workEntries as $entry) {
            $entry->loadMissing([
                'fileEvidences',
                'urlEvidence',
                'mirroredEntries.workRecord.user',
                'sourceEntry.workRecord.user',
            ]);

            $data = [
                'id' => $entry->id,
                'detail' => $entry->detail,
                'result' => $entry->result ?? '',
                'recorded_at' => $entry->created_at?->toIso8601String(),
                'hours' => $entry->hours,
                'evidence_type' => $entry->fileEvidences->isNotEmpty()
                    ? 'file'
                    : ($entry->urlEvidence ? 'url' : 'url'),
                'evidence' => $entry->urlEvidence?->value ?? '',
                'evidence_files' => $entry->fileEvidences->map(fn (WorkEntryEvidence $evidence) => [
                    'id' => $evidence->id,
                    'path' => $evidence->value,
                    'filename' => $evidence->displayName(),
                ])->values()->all(),
            ];

            if ($entry->isMirror()) {
                $data['is_mirror'] = true;
                $data['shared_from_name'] = $entry->sourceEntry?->workRecord?->user?->name ?? '';
            } else {
                $data['is_mirror'] = false;
                $data['collaborator_ids'] = $entry->mirroredEntries
                    ->map(fn (WorkEntry $mirror) => $mirror->workRecord->user_id)
                    ->values()
                    ->all();
                $data['collaborator_names'] = $entry->mirroredEntries
                    ->map(fn (WorkEntry $mirror) => $mirror->workRecord->user->name)
                    ->values()
                    ->all();
            }

            $this->entries[$entry->workload_id][] = $data;
        }
    }

    protected function syncEntriesToDatabase(): void
    {
        if (! $this->workRecord) {
            return;
        }

        $keptIds = [];
        $skippedCollaborators = [];
        $mismatchedCollaborators = [];
        $allowedWorkloadIds = $this->workRecord->workloadStructure?->workloadIds();

        foreach ($this->entries as $workloadId => $items) {
            if ($allowedWorkloadIds !== null && ! in_array((int) $workloadId, $allowedWorkloadIds, true)) {
                continue;
            }

            foreach ($items as $sortOrder => $data) {
                if (blank($data['detail'] ?? null)) {
                    continue;
                }

                if (! empty($data['is_mirror'])) {
                    if ($entryId = $data['id'] ?? null) {
                        $keptIds[] = $entryId;
                    }

                    continue;
                }

                $payload = [
                    'workload_id' => (int) $workloadId,
                    'detail' => $data['detail'],
                    'result' => $data['result'] ?? null,
                    'hours' => $data['hours'] ?: 1,
                    'sort_order' => $sortOrder,
                ];

                if ($entryId = $data['id'] ?? null) {
                    $entry = WorkEntry::find($entryId);
                    if ($entry && ! $entry->isMirror()) {
                        $entry->update($payload);
                        $this->syncEntryEvidences($entry, $data);
                        $keptIds[] = $entry->id;

                        $result = app(SyncCollaboratorWorkEntries::class)(
                            $entry,
                            $data['collaborator_ids'] ?? [],
                        );
                        $skippedCollaborators = array_merge($skippedCollaborators, $result['skipped']);
                        $mismatchedCollaborators = array_merge($mismatchedCollaborators, $result['mismatched'] ?? []);
                    }
                } else {
                    $entry = $this->workRecord->workEntries()->create($payload);
                    $this->syncEntryEvidences($entry, $data);
                    $keptIds[] = $entry->id;

                    $result = app(SyncCollaboratorWorkEntries::class)(
                        $entry,
                        $data['collaborator_ids'] ?? [],
                    );
                    $skippedCollaborators = array_merge($skippedCollaborators, $result['skipped']);
                    $mismatchedCollaborators = array_merge($mismatchedCollaborators, $result['mismatched'] ?? []);
                }
            }
        }

        $this->workRecord->workEntries()
            ->whereNull('source_work_entry_id')
            ->whereNotIn('id', $keptIds)
            ->get()
            ->each(fn (WorkEntry $entry) => $entry->delete());

        if ($skippedCollaborators !== []) {
            $names = collect($skippedCollaborators)
                ->unique('id')
                ->pluck('name')
                ->implode(', ');

            Flux::toast(
                "ไม่สามารถบันทึกงานให้: {$names} (ส่งบันทึกแล้ว)",
                variant: 'warning',
            );
        }

        if ($mismatchedCollaborators !== []) {
            $names = collect($mismatchedCollaborators)
                ->unique('id')
                ->pluck('name')
                ->implode(', ');

            Flux::toast(
                "ไม่สามารถบันทึกงานให้: {$names} (โครงสร้างภาระงานต่างตำแหน่ง)",
                variant: 'warning',
            );
        }

        $this->workRecord->load([
            'workEntries.evidences',
            'workEntries.mirroredEntries.workRecord.user',
            'workEntries.sourceEntry.workRecord.user',
        ]);
        $this->loadEntriesFromRecord();
        $this->bustWorkloadComputeds();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function syncEntryEvidences(WorkEntry $entry, array $data): void
    {
        if (! $this->hasEntryEvidence($data)) {
            $entry->evidences()->get()->each->delete();

            return;
        }

        $type = $data['evidence_type'] ?? 'url';

        if ($type === 'url') {
            $entry->fileEvidences()->get()->each->delete();

            $url = trim((string) ($data['evidence'] ?? ''));

            $entry->urlEvidence()?->delete();

            if (filled($url)) {
                $entry->evidences()->create([
                    'type' => 'url',
                    'value' => $url,
                    'filename' => null,
                    'sort_order' => 0,
                ]);
            }

            return;
        }

        $entry->urlEvidence()?->delete();

        $files = $this->entryEvidenceFiles($data);
        $keptIds = [];

        foreach ($files as $sortOrder => $file) {
            $path = $file['path'] ?? '';
            $filename = $file['filename'] ?? basename($path);

            if (blank($path)) {
                continue;
            }

            if ($id = $file['id'] ?? null) {
                $evidence = WorkEntryEvidence::query()
                    ->where('work_entry_id', $entry->id)
                    ->find($id);

                if ($evidence) {
                    $evidence->update([
                        'value' => $path,
                        'filename' => $filename,
                        'sort_order' => $sortOrder,
                    ]);
                    $keptIds[] = $evidence->id;

                    continue;
                }
            }

            $evidence = $entry->evidences()->create([
                'type' => 'file',
                'value' => $path,
                'filename' => $filename,
                'sort_order' => $sortOrder,
            ]);
            $keptIds[] = $evidence->id;
        }

        $entry->fileEvidences()
            ->when($keptIds !== [], fn ($query) => $query->whereNotIn('id', $keptIds))
            ->when($keptIds === [], fn ($query) => $query)
            ->get()
            ->each
            ->delete();
    }

    protected function deleteStoredEvidence(array $entry): void
    {
        if (($entry['evidence_type'] ?? null) !== 'file') {
            return;
        }

        foreach ($this->entryEvidenceFiles($entry) as $file) {
            if ($id = $file['id'] ?? null) {
                WorkEntryEvidence::find($id)?->delete();
            } elseif ($path = $file['path'] ?? null) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    protected function copyEntryForModal(array $entry): array
    {
        $copy = $entry;
        $copy['evidence_files'] = array_map(
            static fn (array $file): array => [
                'id' => $file['id'] ?? null,
                'path' => $file['path'] ?? '',
                'filename' => $file['filename'] ?? '',
            ],
            $this->entryEvidenceFiles($entry),
        );

        return $copy;
    }

    /**
     * @return list<array{id: int|null, path: string, filename: string}>
     */
    protected function modalEvidenceFiles(): array
    {
        return $this->entryEvidenceFiles($this->modalEntry);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<array{id: int|null, path: string, filename: string}>
     */
    protected function entryEvidenceFiles(array $entry): array
    {
        if (($entry['evidence_type'] ?? null) !== 'file') {
            return [];
        }

        $files = $entry['evidence_files'] ?? [];

        return array_values(array_filter(
            $files,
            fn ($file) => is_array($file) && filled($file['path'] ?? null),
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasEntryEvidence(array $data): bool
    {
        if (($data['evidence_type'] ?? 'url') === 'file') {
            return $this->entryEvidenceFiles($data) !== [];
        }

        return filled($data['evidence'] ?? null);
    }
}; ?>

<div>
    @if (! $readOnly)
        <div
            class="hidden md:block sticky top-20 z-40 -mx-4 mb-6 border-b border-zinc-200 bg-white/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 dark:border-zinc-700 dark:bg-zinc-900/95"
        >
            <div class="flex w-full flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <flux:badge color="blue">{{ $this->totalEntriesCount }} ผลงาน</flux:badge>
                        <flux:badge color="zinc">{{ number_format($this->totalHoursSum, 1) }} งาน</flux:badge>
                        @if ($selectedPeriodId)
                            @php $liveScore = $this->liveScoreSummary; @endphp
                            @if ($liveScore->hasMaximum())
                                <flux:badge color="purple">
                                    {{ number_format($liveScore->earned, 2) }} คะแนน
                                </flux:badge>
                            @elseif ($liveScore->hasEarned())
                                <flux:badge color="purple">{{ number_format($liveScore->earned, 2) }} คะแนน</flux:badge>
                            @endif
                        @endif
                        @php $progress = $this->workloadProgress; @endphp
                        <flux:badge color="green">
                            {{ $progress['filled'] }} ภาระงาน
                        </flux:badge>
                    </div>
                    <div class="flex gap-2">
                        <flux:button wire:click="saveDraft" variant="ghost" size="sm" wire:loading.attr="disabled">
                            บันทึกฉบับร่าง
                        </flux:button>
                    </div>
                </div>

                {{--
                @if ($step === 2)
                    @php $progress = $this->workloadProgress; @endphp
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="mb-1 flex justify-between text-xs text-zinc-500">
                                <span>ความคืบหน้าภาระงานที่บันทึกงานแล้ว</span>
                                <span>{{ $progress['percent'] }}%</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                <div
                                    class="h-full rounded-full bg-blue-500 transition-all duration-300"
                                    style="width: {{ $progress['percent'] }}%"
                                ></div>
                            </div>
                        </div>
                        @if ($progress['filled'] < $progress['total'])
                            <button
                                type="button"
                                wire:click="setWorkloadFilter('empty')"
                                class="cursor-pointer shrink-0 text-xs text-blue-600 hover:underline dark:text-blue-400"
                            >
                                ดูเฉพาะที่ยังไม่บันทึกงาน ({{ $progress['total'] - $progress['filled'] }})
                            </button>
                        @endif
                    </div>
                @endif
                --}}
            </div>
        </div>
    @endif

    <div class="mb-8">
        <flux:heading size="xl">
            @if ($readOnly)
                ดูบันทึกภาระงาน
            @elseif ($workRecord)
                แก้ไขบันทึกภาระงาน
            @else
                สร้างบันทึกภาระงาน
            @endif
        </flux:heading>
        @if ($this->selectedPeriod)
            <flux:subheading>{{ $this->selectedPeriod->label }}</flux:subheading>
        @endif
    </div>

    {{-- Step indicators --}}
    <div class="mb-8 flex gap-2">
        @foreach ([1 => 'เลือกรอบ', 2 => 'บันทึกงาน', 3 => 'สรุปและส่ง'] as $num => $label)
            <button
                type="button"
                wire:click="goToStep({{ $num }})"
                @class([
                    'cursor-pointer flex-1 rounded-lg border px-4 py-3 text-left text-sm transition',
                    'border-blue-500 bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300' => $step === $num,
                    'border-zinc-200 bg-white text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400' => $step !== $num,
                ])
            >
                <span class="font-medium">{{ $num }}. {{ $label }}</span>
            </button>
        @endforeach
    </div>

    {{-- Step 1 --}}
    @if ($step === 1)
        <div class="grid gap-4 sm:grid-cols-2">
            @forelse ($this->openPeriods as $period)
                <button
                    type="button"
                    wire:click="selectPeriod({{ $period->id }})"
                    wire:key="period-{{ $period->id }}"
                    @class([
                        'cursor-pointer rounded-xl border p-6 text-left transition hover:shadow-md',
                        'border-blue-500 ring-2 ring-blue-500/20' => $selectedPeriodId === $period->id,
                        'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800' => $selectedPeriodId !== $period->id,
                    ])
                    @disabled($readOnly)
                >
                    <div class="text-lg font-semibold">{{ $period->label }}</div>
                    <div class="mt-1 text-sm text-zinc-500">
                        ปีงบประมาณ {{ $period->fiscalYear->year }}
                    </div>
                    <flux:badge class="mt-3" color="green" size="sm">{{ $period->status->label() }}</flux:badge>
                </button>
            @empty
                <x-empty-state icon="calendar-days" message="ไม่มีรอบประเมินที่เปิดรับในขณะนี้" class="sm:col-span-2" />
            @endforelse
        </div>

        @if ($selectedPeriodId && ! $readOnly && $this->structureMissingMessage)
            <flux:callout class="mt-6" variant="warning" icon="exclamation-triangle">
                {{ $this->structureMissingMessage }}
            </flux:callout>
        @endif

        @if ($selectedPeriodId && ! $readOnly)
            <div class="mt-6 flex justify-end">
                <flux:button wire:click="goToStep(2)" variant="primary" :disabled="! $this->structureId">ถัดไป</flux:button>
            </div>
        @endif
    @endif

    {{-- Step 2 --}}
    @if ($step === 2)
        <div class="mb-4 lg:hidden">
            <flux:button wire:click="$set('sidebarDrawerOpen', true)" variant="outline" icon="bars-3" class="w-full">
                เลือกภาระงาน
                {{--
                @if ($this->activeWorkload)
                    <span class="invisible md:visible ml-1 truncate text-zinc-500">— {{ $this->activeWorkload['name'] }}</span>
                @endif
                --}}
            </flux:button>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(280px,600px)_1fr]">
            <x-work-record.step2-sidebar
                :read-only="$readOnly"
                :workload-filter="$workloadFilter"
                :selected-workload-id="$selectedWorkloadId"
                :filtered-workload-tree="$this->filteredWorkloadTree"
                :structure-numbers="$this->structureNumbers"
                :category-totals="$this->categoryTotals"
                class="sticky top-36 hidden max-h-[calc(100vh-10rem)] lg:flex"
            />

            <x-work-record.step2-detail
                wire:key="workload-detail-{{ $selectedWorkloadId }}-{{ count($entries[$selectedWorkloadId] ?? []) }}-{{ $this->selectedWorkloadShare?->enabled_at?->timestamp }}"
                :read-only="$readOnly"
                :active-workload="$this->activeWorkload"
                :selected-workload-id="$selectedWorkloadId"
                :entries="$entries"
                :can-share="$this->canShareSelectedWorkload"
                :share-enabled="$this->selectedWorkloadShare?->isEnabled() ?? false"
                :share-url="$this->selectedWorkloadShare?->isEnabled() ? $this->selectedWorkloadShare->shareUrl() : null"
            />
        </div>

        <flux:modal wire:model="sidebarDrawerOpen" class="w-full max-w-lg lg:hidden p-2!" flyout>
            <div class="space-y-4 mt-6">
                <x-work-record.step2-sidebar
                    :read-only="$readOnly"
                    :workload-filter="$workloadFilter"
                    :selected-workload-id="$selectedWorkloadId"
                    :filtered-workload-tree="$this->filteredWorkloadTree"
                    :structure-numbers="$this->structureNumbers"
                    :category-totals="$this->categoryTotals"
                    class="border-none"
                />
            </div>
        </flux:modal>

        <div class="mt-6 flex justify-between">
            @if ($readOnly)
                <flux:button wire:click="goToStep(3)" variant="ghost">กลับไปสรุป</flux:button>
            @else
                <flux:button wire:click="goToStep(1)" variant="ghost">ย้อนกลับ</flux:button>
            @endif
            @unless ($readOnly)
                <flux:button wire:click="goToStep(3)" variant="primary">ถัดไป</flux:button>
            @endunless
        </div>
    @endif

    {{-- Step 3 --}}
    @if ($step === 3)
        @php
            $chartData = $this->step3ChartData;
            $progress = $this->workloadProgress;
            $score = $this->liveScoreSummary;
            $emptyWorkloads = $progress['total'] - $progress['filled'];
        @endphp
        <script type="application/json" id="step3ChartPayload">@json($chartData)</script>

        {{-- Overview --}}
        <section class="mb-6 overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex flex-wrap items-center justify-between gap-3 px-6 pt-5 pb-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($workRecord)
                            <flux:badge :color="$workRecord->status->color()" size="sm">{{ $workRecord->status->label() }}</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">ยังไม่บันทึก</flux:badge>
                        @endif
                        @if ($workRecord?->submitted_at)
                            <span class="text-xs text-zinc-500">ส่งเมื่อ {{ $workRecord->submitted_at->format('d/m/Y H:i') }}</span>
                        @endif
                    </div>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        @if ($readOnly)
                            สรุปภาระงานที่ส่งแล้ว ดูรายการผลงานหรือพิมพ์เอกสารได้จากปุ่มด้านล่าง
                        @else
                            ตรวจสอบตัวเลขด้านล่างก่อนกดส่ง เมื่อส่งแล้วจะแก้ไขไม่ได้จนกว่าจะถอนการส่ง
                        @endif
                    </p>
                </div>
            </div>

            <dl class="grid grid-cols-2 gap-px border-t border-zinc-200 bg-zinc-200 lg:grid-cols-4 dark:border-zinc-700 dark:bg-zinc-700">
                <div class="bg-white px-6 py-4 dark:bg-zinc-800">
                    <dt class="text-xs font-medium text-zinc-500">ผลงาน</dt>
                    <dd class="mt-1 text-2xl font-semibold tracking-tight tabular-nums">
                        {{ number_format($this->totalEntriesCount) }}
                        <span class="text-sm font-normal text-zinc-500">รายการ</span>
                    </dd>
                </div>
                <div class="bg-white px-6 py-4 dark:bg-zinc-800">
                    <dt class="text-xs font-medium text-zinc-500">งานรวม</dt>
                    <dd class="mt-1 text-2xl font-semibold tracking-tight tabular-nums">
                        {{ number_format($this->totalHoursSum, 1) }}
                        <span class="text-sm font-normal text-zinc-500">งาน</span>
                    </dd>
                </div>
                <div class="bg-white px-6 py-4 dark:bg-zinc-800">
                    <dt class="text-xs font-medium text-zinc-500">คะแนนที่ได้</dt>
                    <dd class="mt-1 text-2xl font-semibold tracking-tight tabular-nums">
                        @if ($score->hasMaximum() || $score->hasEarned())
                            {{ number_format($score->earned, 2) }}
                            <span class="text-sm font-normal text-zinc-500">คะแนน</span>
                        @else
                            <span class="text-zinc-400">—</span>
                        @endif
                    </dd>
                </div>
                <div class="bg-white px-6 py-4 dark:bg-zinc-800">
                    <dt class="flex items-center justify-between text-xs font-medium text-zinc-500">
                        <span>ภาระงานที่บันทึกแล้ว</span>
                        <span class="tabular-nums">{{ $progress['percent'] }}%</span>
                    </dt>
                    <dd class="mt-1 text-2xl font-semibold tracking-tight tabular-nums">
                        {{ $progress['filled'] }}
                        <span class="text-sm font-normal text-zinc-500">/ {{ $progress['total'] }}</span>
                    </dd>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700">
                        <div class="h-full rounded-full bg-blue-500 transition-[width] duration-300" style="width: {{ $progress['percent'] }}%"></div>
                    </div>
                </div>
            </dl>
        </section>

        {{-- Notices --}}
        @if ($workRecord?->rejection_comment || ! $readOnly)
        <div class="mb-6 space-y-3">
            @if ($workRecord?->rejection_comment)
                <flux:callout variant="danger" icon="exclamation-triangle" heading="ถูกส่งกลับให้แก้ไข">
                    <flux:callout.text>{{ $workRecord->rejection_comment }}</flux:callout.text>
                </flux:callout>
            @endif

            @unless ($readOnly)
                @if ($this->totalEntriesCount === 0)
                    <flux:callout variant="danger" icon="x-circle" heading="ยังไม่มีผลงาน">
                        <flux:callout.text>ต้องบันทึกผลงานอย่างน้อย 1 รายการก่อนส่ง</flux:callout.text>
                        <x-slot name="actions">
                            <flux:button wire:click="goToStep(2)" size="sm">ไปบันทึกงาน</flux:button>
                        </x-slot>
                    </flux:callout>
                @endif

                @if ($this->totalEntriesCount > 0)
                    <flux:callout variant="secondary" icon="link">
                        <flux:callout.text>เมื่อส่งแล้ว ระบบจะสร้างลิงก์สรุปผลงานของทุกภาระงานเพื่อคัดลอกไปยังระบบอื่น</flux:callout.text>
                    </flux:callout>
                @endif
            @endunless
        </div>
        @endif

        {{-- Charts --}}
        <div class="mb-6 grid gap-4 lg:grid-cols-2">
            <section class="flex flex-col rounded-2xl border border-zinc-200 bg-white p-6 lg:row-span-2 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading>จำนวนงานตามกลุ่มภาระงาน</flux:heading>
                <flux:text class="mt-1">เทียบปริมาณงานของแต่ละกลุ่ม</flux:text>
                <div wire:ignore class="relative mt-4 min-h-72 flex-1">
                    <div class="absolute inset-0">
                        <canvas id="step3RadarChart"></canvas>
                    </div>
                    @unless ($chartData['hasEntries'])
                        <div class="absolute inset-0 flex items-center justify-center text-sm text-zinc-400">
                            ยังไม่บันทึกงาน
                        </div>
                    @endunless
                </div>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading>สัดส่วนงาน</flux:heading>
                <div wire:ignore class="relative mt-4 h-56">
                    <canvas id="step3HoursChart"></canvas>
                    @unless ($chartData['hasEntries'])
                        <div class="absolute inset-0 flex items-center justify-center text-sm text-zinc-400">
                            ยังไม่บันทึกงาน
                        </div>
                    @endunless
                </div>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading>ความคืบหน้าภาระงาน</flux:heading>
                <div wire:ignore class="relative mt-4 h-56">
                    <canvas id="step3ProgressChart"></canvas>
                    @unless ($chartData['hasEntries'])
                        <div class="absolute inset-0 flex items-center justify-center text-sm text-zinc-400">
                            ยังไม่บันทึกงาน
                        </div>
                    @endunless
                </div>
            </section>
        </div>

        {{-- Per-group breakdown --}}
        <section class="mb-6 rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="px-6 pt-5 pb-3">
                <flux:heading>สรุปตามกลุ่มภาระงาน</flux:heading>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[36rem] text-sm">
                    <thead>
                        <tr class="border-y border-zinc-100 text-left text-xs text-zinc-500 dark:border-zinc-700">
                            <th class="px-6 py-2 font-medium">กลุ่ม</th>
                            <th class="px-3 py-2 text-right font-medium">ภาระงานที่บันทึก</th>
                            <th class="px-3 py-2 text-right font-medium">ผลงาน</th>
                            <th class="px-3 py-2 text-right font-medium">งาน</th>
                            <th class="w-48 px-6 py-2 text-right font-medium">คะแนน</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @foreach ($this->groupSummaries as $group)
                            <tr wire:key="group-summary-{{ $group['id'] }}" @class(['text-zinc-400' => $group['entry_count'] === 0])>
                                <td class="px-6 py-3 font-medium">{{ $group['label'] }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $group['workloads_filled'] }} / {{ $group['workloads_total'] }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ number_format($group['entry_count']) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ number_format($group['hours'], 1) }}</td>
                                <td class="px-6 py-3 text-right tabular-nums">
                                    {{ number_format($group['score'], 2) }}
                                    @if ($group['max_score'] !== null)
                                        <span class="text-zinc-400">/ {{ number_format($group['max_score'], 2) }}</span>
                                        <div class="mt-1 ml-auto h-1 w-24 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700">
                                            <div
                                                class="h-full rounded-full bg-blue-500"
                                                style="width: {{ $group['max_score'] > 0 ? min(100, round($group['score'] / $group['max_score'] * 100)) : 0 }}%"
                                            ></div>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Share links --}}
        @if ($this->canManageShareLinks)
            @php $shareLinks = $this->sharedWorkloadLinks; @endphp
            <section
                class="mb-6 rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800"
                x-data="{ allLinks: @js($shareLinks->map(fn (array $link) => trim($link['number'].' '.$link['name'])."\t".$link['url'])->implode("\n")) }"
            >
                <div class="flex flex-wrap items-start justify-between gap-3 px-6 pt-5 pb-4">
                    <div class="min-w-0">
                        <flux:heading>ลิงก์สรุปผลงานที่แชร์</flux:heading>
                        <flux:text class="mt-1 max-w-prose">คัดลอกไปกรอกในระบบภายนอกได้ ผู้เปิดลิงก์ไม่ต้องล็อกอิน</flux:text>
                    </div>
                    @if ($shareLinks->isNotEmpty())
                        <flux:button
                            type="button"
                            variant="primary"
                            size="sm"
                            icon="clipboard-document-list"
                            x-on:click="window.copyText(allLinks).then(() => $wire.notifyShareLinkCopied())"
                        >
                            คัดลอกทั้งหมด ({{ $shareLinks->count() }})
                        </flux:button>
                    @endif
                </div>

                @if ($shareLinks->isNotEmpty())
                    <ul class="divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach ($shareLinks as $link)
                            <li
                                wire:key="share-link-{{ $link['workload_id'] }}"
                                class="group flex items-center gap-3 px-6 py-3 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-900/40"
                                x-data="{ url: @js($link['url']) }"
                            >
                                @if (filled($link['number']))
                                    <span class="inline-flex h-6 min-w-10 shrink-0 items-center justify-center self-start rounded-md bg-blue-50 px-1.5 font-mono text-xs font-semibold text-blue-700 tabular-nums ring-1 ring-blue-200 ring-inset dark:bg-blue-950 dark:text-blue-300 dark:ring-blue-800">
                                        {{ $link['number'] }}
                                    </span>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium">{{ $link['name'] }}</p>
                                    <p class="truncate text-xs text-zinc-500">
                                        {{ $link['group'] }}
                                        @if ($link['main'])
                                            <span class="mx-1">›</span>{{ $link['main'] }}
                                        @endif
                                    </p>
                                    <p class="mt-1 truncate font-mono text-xs text-zinc-400 select-all">{{ $link['url'] }}</p>
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    <flux:button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        icon="clipboard"
                                        tooltip="คัดลอกลิงก์"
                                        aria-label="คัดลอกลิงก์"
                                        x-on:click="window.copyText(url).then(() => $wire.notifyShareLinkCopied())"
                                    />
                                    <flux:button
                                        :href="$link['url']"
                                        variant="ghost"
                                        size="sm"
                                        icon="arrow-top-right-on-square"
                                        tooltip="เปิดลิงก์"
                                        aria-label="เปิดลิงก์"
                                        target="_blank"
                                    />
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 px-6 py-4 text-sm text-zinc-500 dark:border-zinc-700">
                        <span>ยังไม่มีลิงก์แชร์สำหรับบันทึกนี้</span>
                        <flux:button
                            wire:click="enableAllWorkloadShares"
                            variant="primary"
                            size="sm"
                            icon="share"
                            wire:loading.attr="disabled"
                        >
                            สร้างลิงก์ทุกภาระงาน
                        </flux:button>
                    </div>
                @endif
            </section>
        @endif

        {{-- Actions --}}
        <div class="sticky bottom-0 z-10 -mx-4 border-t border-zinc-200 bg-white/90 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border dark:border-zinc-700 dark:bg-zinc-800/90">
            <div class="flex flex-wrap items-center justify-between gap-3">
                @unless ($readOnly)
                    <flux:button wire:click="goToStep(2)" variant="ghost" icon="arrow-left">ย้อนกลับ</flux:button>
                    <div class="flex gap-2">
                        <flux:button wire:click="saveDraft" variant="ghost" wire:loading.attr="disabled">บันทึกฉบับร่าง</flux:button>
                        <flux:button
                            wire:click="submit"
                            variant="primary"
                            icon-trailing="paper-airplane"
                            wire:loading.attr="disabled"
                            :disabled="$this->totalEntriesCount === 0"
                        >
                            ส่งบันทึก
                        </flux:button>
                    </div>
                @else
                    <flux:button :href="route('staff.records.index')" variant="ghost" icon="arrow-left" wire:navigate>กลับ</flux:button>
                    <div class="flex flex-col items-end gap-1">
                        <div class="flex flex-wrap justify-end gap-2">
                            <flux:button wire:click="goToStep(2)" variant="ghost" icon="clipboard-document-list">
                                ดูรายการผลงาน
                            </flux:button>
                            @if ($workRecord)
                                <flux:button :href="route('staff.records.print', $workRecord)" variant="filled" icon="printer" target="_blank">
                                    พิมพ์
                                </flux:button>
                            @endif
                            @if ($this->canWithdraw)
                                <flux:button
                                    wire:click="openWithdrawConfirm"
                                    variant="danger"
                                    icon="arrow-uturn-left"
                                    wire:loading.attr="disabled"
                                >
                                    ถอนการส่ง
                                </flux:button>
                            @endif
                        </div>
                        @if ($this->withdrawUnavailableMessage)
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $this->withdrawUnavailableMessage }}</p>
                        @endif
                    </div>
                @endunless
            </div>
        </div>
    @endif

    {{-- Entry modal --}}
    <flux:modal wire:model="entryModalOpen" class="md:w-lg" flyout>
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">
                    @if ($readOnly)
                        รายละเอียดผลงาน
                    @elseif ($modalEntryIndex !== null)
                        แก้ไขผลงาน
                    @else
                        บันทึกงาน
                    @endif
                </flux:heading>
                @if ($modalWorkloadId)
                    @php $modalWl = $this->flatWorkloads->firstWhere('id', $modalWorkloadId); @endphp
                    @if ($modalWl)
                        <flux:subheading class="mt-1">{{ $modalWl['name'] }}</flux:subheading>
                    @endif
                @endif
            </div>

            <x-work-entry-fields
                :readonly="$readOnly || $modalReadOnly"
                :modal-entry="$modalEntry"
                :evidence-uploads="$evidenceUploads"
            />

            <div class="flex justify-between gap-2">
                @if (! $readOnly && $modalEntryIndex !== null && ! ($modalEntry['is_mirror'] ?? false))
                    <flux:button
                        type="button"
                        wire:click="openDeleteEntryConfirm"
                        variant="danger"
                        wire:loading.attr="disabled"
                    >
                        ลบ
                    </flux:button>
                @else
                    <div></div>
                @endif

                <div class="flex gap-2">
                    <flux:button wire:click="closeEntryModal" variant="ghost">
                        {{ ($readOnly || $modalReadOnly) ? 'ปิด' : 'ยกเลิก' }}
                    </flux:button>
                    @unless ($readOnly || $modalReadOnly)
                        <flux:button wire:click="saveModalEntry" variant="primary" wire:loading.attr="disabled">
                            บันทึก
                        </flux:button>
                    @endunless
                </div>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="deleteEntryConfirmOpen" class="md:w-sm">
        <div class="space-y-4">
            <flux:heading size="lg">ลบผลงาน</flux:heading>
            <flux:subheading>ต้องการลบผลงานนี้ใช่หรือไม่? การดำเนินการนี้ไม่สามารถย้อนกลับได้</flux:subheading>
            <div class="flex justify-end gap-2">
                <flux:button wire:click="cancelDeleteEntryConfirm" variant="ghost">ยกเลิก</flux:button>
                <flux:button
                    wire:click="confirmDeleteModalEntry"
                    variant="danger"
                    wire:loading.attr="disabled"
                >
                    ลบ
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="withdrawConfirmOpen" class="md:w-sm">
        <div class="space-y-4">
            <flux:heading size="lg">ถอนการส่ง</flux:heading>
            <flux:subheading>
                บันทึกจะกลับเป็นฉบับร่าง และลิงก์แชร์จะใช้งานไม่ได้จนกว่าจะส่งใหม่ ต้องการถอนการส่งใช่หรือไม่?
            </flux:subheading>
            <div class="flex justify-end gap-2">
                <flux:button wire:click="cancelWithdrawConfirm" variant="ghost">ยกเลิก</flux:button>
                <flux:button
                    wire:click="confirmWithdraw"
                    variant="danger"
                    wire:loading.attr="disabled"
                >
                    ถอนการส่ง
                </flux:button>
            </div>
        </div>
    </flux:modal>

    @script
    <script>
        let step3RadarChart = null;
        let step3HoursChart = null;
        let step3ProgressChart = null;

        const step3HoursColors = ['#60a5fa', '#34d399', '#fbbf24', '#fb7185', '#a78bfa', '#22d3ee'];

        function destroyStep3Charts() {
            [step3RadarChart, step3HoursChart, step3ProgressChart].forEach((chart) => {
                if (chart) {
                    chart.destroy();
                }
            });
            step3RadarChart = null;
            step3HoursChart = null;
            step3ProgressChart = null;
        }

        function renderStep3Charts() {
            if ($wire.step !== 3) {
                destroyStep3Charts();

                return;
            }

            const payloadEl = document.getElementById('step3ChartPayload');

            if (! payloadEl) {
                return;
            }

            const data = JSON.parse(payloadEl.textContent);
            const Chart = window.Chart;

            if (! Chart) {
                return;
            }

            const radarCanvas = document.getElementById('step3RadarChart');
            const hoursCanvas = document.getElementById('step3HoursChart');
            const progressCanvas = document.getElementById('step3ProgressChart');

            if (!radarCanvas || !hoursCanvas || !progressCanvas) {
                return;
            }

            destroyStep3Charts();

            const radar = data.hoursRadar ?? { labels: [], values: [] };

            step3RadarChart = new Chart(radarCanvas, {
                type: 'radar',
                data: {
                    labels: radar.labels,
                    datasets: [{
                        label: 'จำนวนงาน',
                        data: radar.values,
                        backgroundColor: 'rgba(168, 85, 247, 0.2)',
                        borderColor: '#a855f7',
                        pointBackgroundColor: '#a855f7',
                        borderWidth: 2,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => `${ctx.label}: ${ctx.parsed.r.toFixed(1)} งาน`,
                            },
                        },
                    },
                    scales: {
                        r: {
                            beginAtZero: true,
                            ticks: { precision: 0 },
                        },
                    },
                },
            });

            const hours = data.hoursByGroup ?? { labels: [], values: [] };

            step3HoursChart = new Chart(hoursCanvas, {
                type: 'doughnut',
                data: {
                    labels: hours.labels,
                    datasets: [{
                        data: hours.values,
                        backgroundColor: hours.values.map((_, i) => step3HoursColors[i % step3HoursColors.length]),
                        borderWidth: 0,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => `${ctx.label}: ${ctx.parsed.toFixed(1)} งาน`,
                            },
                        },
                    },
                },
            });

            const progress = data.progress ?? { filled: 0, empty: 0, percent: 0 };

            step3ProgressChart = new Chart(progressCanvas, {
                type: 'doughnut',
                data: {
                    labels: ['บันทึกงานแล้ว', 'ยังไม่บันทึกงาน'],
                    datasets: [{
                        data: [progress.filled, progress.empty],
                        backgroundColor: ['#4ade80', '#e4e4e7'],
                        borderWidth: 0,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%',
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => `${ctx.label}: ${ctx.parsed} ภาระงาน`,
                            },
                        },
                    },
                },
            });
        }

        function scheduleStep3Charts() {
            setTimeout(() => renderStep3Charts(), 50);
        }

        if ($wire.step === 3) {
            scheduleStep3Charts();
        }

        $wire.$watch('step', (step) => {
            if (step === 3) {
                scheduleStep3Charts();
            } else {
                destroyStep3Charts();
            }
        });

        $wire.$watch('totalEntriesCount', () => {
            if ($wire.step === 3) {
                scheduleStep3Charts();
            }
        });
    </script>
    @endscript
</div>
