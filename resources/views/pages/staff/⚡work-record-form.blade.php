<?php

use App\Data\WorkRecordScoreSummary;
use App\Enums\WorkRecordStatus;
use App\Models\EvaluationPeriod;
use App\Models\WorkEntry;
use App\Models\WorkEntryEvidence;
use App\Models\WorkRecord;
use App\Models\WorkloadGroup;
use App\Models\WorkloadShare;
use App\Support\WorkloadStructureNumbering;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public string $selfAssessment = '';

    public ?int $selfScore = null;

    public bool $readOnly = false;

    public bool $entryModalOpen = false;

    public bool $deleteEntryConfirmOpen = false;

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
    ];

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $evidenceUploads = [];

    public ?int $selectedWorkloadId = null;

    public string $workloadSearch = '';

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
            ]);

            $this->selectedPeriodId = $record->evaluation_period_id;
            $this->selfAssessment = $record->self_assessment ?? '';
            $this->selfScore = $record->self_score;
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

    #[Computed]
    public function workloadGroups()
    {
        $period = $this->selectedPeriod;

        if (! $period) {
            return collect();
        }

        return WorkloadGroup::query()
            ->forFiscalYear($period->fiscal_year_id)
            ->with(['workloadMains.workloads'])
            ->get();
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

    public function selectPeriod(int $periodId): void
    {
        if ($this->readOnly) {
            return;
        }

        $this->selectedPeriodId = $periodId;
        $this->selectedWorkloadId = null;
        unset($this->selectedPeriod, $this->workloadGroups);
    }

    public function goToStep(int $step): void
    {
        if ($step === 2 && ! $this->selectedPeriodId) {
            Flux::toast('กรุณาเลือกรอบประเมิน', variant: 'danger');

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

    public function setWorkloadFilter(string $filter): void
    {
        if (! in_array($filter, ['all', 'filled', 'empty'], true)) {
            return;
        }

        $this->workloadFilter = $filter;
        $this->ensureSelectedWorkload();
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

    public function autoSave(): void
    {
        if ($this->readOnly || ! $this->selectedPeriodId || $this->entryModalOpen) {
            return;
        }

        $this->saveDraft(showToast: false);
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
                $this->workRecord = WorkRecord::create([
                    'user_id' => Auth::id(),
                    'evaluation_period_id' => $this->selectedPeriodId,
                    'status' => WorkRecordStatus::Draft,
                ]);
            }
        }

        Gate::authorize('update', $this->workRecord);

        $this->workRecord->update([
            'self_assessment' => $this->selfAssessment,
            'self_score' => $this->selfScore,
        ]);

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

        Gate::authorize('submit', $this->workRecord);

        if ($this->totalEntriesCount === 0) {
            Flux::toast('กรุณาเพิ่มผลงานอย่างน้อย 1 รายการ', variant: 'danger');

            return;
        }

        $this->workRecord->update([
            'status' => WorkRecordStatus::Submitted,
            'submitted_at' => now(),
            'rejection_comment' => null,
            'approved_at' => null,
        ]);

        Flux::toast('ส่งบันทึกภาระงานเรียบร้อยแล้ว');

        $this->redirect(route('staff.records.index'), navigate: true);
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

        if ($index !== null && isset($this->entries[$workloadId][$index])) {
            $this->modalEntry = $this->entries[$workloadId][$index];
        } else {
            $this->modalEntry = [
                'id' => null,
                'detail' => '',
                'result' => '',
                'hours' => '',
                'evidence_type' => 'url',
                'evidence' => '',
                'evidence_files' => [],
            ];
        }

        $this->entryModalOpen = true;
    }

    public function openDeleteEntryConfirm(): void
    {
        if ($this->readOnly || $this->modalEntryIndex === null || ! $this->modalWorkloadId) {
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

    public function closeEntryModal(): void
    {
        $this->entryModalOpen = false;
        $this->deleteEntryConfirmOpen = false;
        $this->modalWorkloadId = null;
        $this->modalEntryIndex = null;
        $this->modalEntry = [
            'id' => null,
            'detail' => '',
            'result' => '',
            'hours' => '',
            'evidence_type' => 'url',
            'evidence' => '',
            'evidence_files' => [],
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

    public function saveModalEntry(): void
    {
        if ($this->readOnly) {
            $this->closeEntryModal();

            return;
        }

        $rules = [
            'modalEntry.detail' => ['required', 'string', 'min:10'],
            'modalEntry.hours' => ['nullable', 'numeric', 'min:1', 'max:999'],
        ];

        if (($this->modalEntry['evidence_type'] ?? 'url') === 'url') {
            $rules['modalEntry.evidence'] = ['nullable', 'url', 'max:2048'];
        } else {
            $hasExistingFiles = ($this->modalEntry['evidence_type'] ?? 'url') === 'file'
                && $this->modalEvidenceFiles() !== [];

            $rules['evidenceUploads'] = [$hasExistingFiles || $this->evidenceUploads !== [] ? 'nullable' : 'required', 'array'];
            $rules['evidenceUploads.*'] = [
                'file',
                'max:10240',
                'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp',
            ];
        }

        $this->validate($rules);

        if (! $this->workRecord && $this->selectedPeriodId) {
            $this->saveDraft(showToast: false);
        }

        $workloadId = $this->modalWorkloadId;
        $this->entries[$workloadId] ??= [];

        $entryData = $this->modalEntry;

        if (($entryData['evidence_type'] ?? 'url') === 'file' && $this->workRecord) {
            $storedFiles = $this->modalEvidenceFiles();

            foreach ($this->evidenceUploads as $upload) {
                $storedFiles[] = [
                    'id' => null,
                    'path' => $upload->store(
                        'work-evidence/'.$this->workRecord->id,
                        'public',
                    ),
                    'filename' => $upload->getClientOriginalName(),
                ];
            }

            $entryData['evidence_files'] = array_values($storedFiles);
            $entryData['evidence_type'] = 'file';
            $this->evidenceUploads = [];
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

        $this->workRecord?->update([
            'self_assessment' => $this->selfAssessment,
            'self_score' => $this->selfScore,
        ]);

        $this->syncEntriesToDatabase();
        $this->bustWorkloadComputeds();
        $this->selectedWorkloadId = $workloadId;

        Flux::toast('บันทึกผลงานแล้ว');
        $this->closeEntryModal();
    }

    public function deleteModalEntry(): void
    {
        if ($this->readOnly || $this->modalEntryIndex === null || ! $this->modalWorkloadId) {
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
        $this->deleteStoredEvidence($entry);

        if ($entryId = $entry['id'] ?? null) {
            WorkEntry::find($entryId)?->delete();
        }

        unset($this->entries[$workloadId][$index]);
        $this->entries[$workloadId] = array_values($this->entries[$workloadId]);

        if ($this->workRecord) {
            $this->workRecord->update([
                'self_assessment' => $this->selfAssessment,
                'self_score' => $this->selfScore,
            ]);
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

        $this->deleteStoredEvidence($this->entries[$workloadId][$index]);

        if ($entryId = $this->entries[$workloadId][$index]['id'] ?? null) {
            WorkEntry::find($entryId)?->delete();
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
            $entry->loadMissing(['fileEvidences', 'urlEvidence']);

            $this->entries[$entry->workload_id][] = [
                'id' => $entry->id,
                'detail' => $entry->detail,
                'result' => $entry->result ?? '',
                'recorded_at' => $entry->created_at?->toIso8601String(),
                'hours' => $entry->hours,
                'evidence_type' => $entry->urlEvidence
                    ? 'url'
                    : ($entry->fileEvidences->isNotEmpty() ? 'file' : 'url'),
                'evidence' => $entry->urlEvidence?->value ?? '',
                'evidence_files' => $entry->fileEvidences->map(fn (WorkEntryEvidence $evidence) => [
                    'id' => $evidence->id,
                    'path' => $evidence->value,
                    'filename' => $evidence->displayName(),
                ])->values()->all(),
            ];
        }
    }

    protected function syncEntriesToDatabase(): void
    {
        if (! $this->workRecord) {
            return;
        }

        $keptIds = [];

        foreach ($this->entries as $workloadId => $items) {
            foreach ($items as $sortOrder => $data) {
                if (blank($data['detail'] ?? null)) {
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
                    if ($entry) {
                        $entry->update($payload);
                        $this->syncEntryEvidences($entry, $data);
                        $keptIds[] = $entry->id;
                    }
                } else {
                    $entry = $this->workRecord->workEntries()->create($payload);
                    $this->syncEntryEvidences($entry, $data);
                    $keptIds[] = $entry->id;
                }
            }
        }

        $this->workRecord->workEntries()
            ->whereNotIn('id', $keptIds)
            ->get()
            ->each(fn (WorkEntry $entry) => $entry->delete());

        $this->workRecord->load(['workEntries.evidences']);
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
                }

                continue;
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
            @if ($step === 2) wire:poll.30s="autoSave" @endif
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
                        <span class="text-zinc-500">บันทึกอัตโนมัติทุก 30 วินาที</span>
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
                                <span>ความคืบหน้าภาระงานที่มีผลงานแล้ว</span>
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
                                ดูเฉพาะที่ยังไม่มีผลงาน ({{ $progress['total'] - $progress['filled'] }})
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

        @if ($selectedPeriodId && ! $readOnly)
            <div class="mt-6 flex justify-end">
                <flux:button wire:click="goToStep(2)" variant="primary">ถัดไป</flux:button>
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
        @if ($readOnly)
            <div class="mb-6 flex flex-wrap gap-3 text-sm">
                <flux:badge color="blue">{{ $this->totalEntriesCount }} ผลงาน</flux:badge>
                <flux:badge color="zinc">{{ number_format($this->totalHoursSum, 1) }} งาน</flux:badge>
                @php $liveScore = $this->liveScoreSummary; @endphp
                @if ($liveScore->hasMaximum())
                    <flux:badge color="purple">
                        {{ number_format($liveScore->earned, 2) }} คะแนน
                    </flux:badge>
                @elseif ($liveScore->hasEarned())
                    <flux:badge color="purple">{{ number_format($liveScore->earned, 2) }} คะแนน</flux:badge>
                @endif
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg" class="mb-4">สรุปผลงาน</flux:heading>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-zinc-500">จำนวนผลงาน</dt>
                        <dd class="font-medium">{{ $this->totalEntriesCount }} รายการ</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-zinc-500">งานรวม</dt>
                        <dd class="font-medium">{{ number_format($this->totalHoursSum, 1) }} งาน</dd>
                    </div>
                    @php $summaryScore = $this->liveScoreSummary; @endphp
                    @if ($summaryScore->hasMaximum() || $summaryScore->hasEarned())
                        <div class="flex justify-between">
                            <dt class="text-zinc-500">คะแนนที่ได้</dt>
                            <dd class="font-medium text-purple-600 dark:text-purple-400">
                                @if ($summaryScore->hasMaximum())
                                    {{ number_format($summaryScore->earned, 2) }}
                                @else
                                    {{ number_format($summaryScore->earned, 2) }}
                                @endif
                                คะแนน
                            </dd>
                        </div>
                    @endif
                    @if ($workRecord)
                        <div class="flex justify-between">
                            <dt class="text-zinc-500">สถานะ</dt>
                            <dd><flux:badge :color="$workRecord->status->color()" size="sm">{{ $workRecord->status->label() }}</flux:badge></dd>
                        </div>
                    @endif
                </dl>

                @if ($workRecord?->rejection_comment)
                    <div class="mt-4 rounded-lg bg-red-50 p-4 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
                        <strong>เหตุผลที่ส่งกลับ:</strong> {{ $workRecord->rejection_comment }}
                    </div>
                @endif
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg" class="mb-4">การประเมินตนเอง</flux:heading>
                <div class="space-y-4">
                    <flux:field>
                        <flux:label>สรุปการประเมินตนเอง</flux:label>
                        <flux:textarea wire:model="selfAssessment" rows="5" :disabled="$readOnly" />
                    </flux:field>
                    <flux:field>
                        <flux:label>คะแนนประเมินตนเอง</flux:label>
                        <x-star-rating wire:model="selfScore" :score="$selfScore" :disabled="$readOnly" :readonly="$readOnly" />
                    </flux:field>
                </div>
            </div>
        </div>

        <div class="mt-6 flex justify-between">
            @unless ($readOnly)
                <flux:button wire:click="goToStep(2)" variant="ghost">ย้อนกลับ</flux:button>
                <div class="flex gap-2">
                    <flux:button wire:click="saveDraft" variant="ghost" wire:loading.attr="disabled">บันทึกฉบับร่าง</flux:button>
                    <flux:button wire:click="submit" variant="primary" wire:loading.attr="disabled">ส่งบันทึก</flux:button>
                </div>
            @else
                <flux:button :href="route('staff.records.index')" variant="ghost" wire:navigate>กลับ</flux:button>
                <div class="flex flex-col items-end gap-2">
                    @if ($this->withdrawUnavailableMessage)
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $this->withdrawUnavailableMessage }}</p>
                    @endif
                    <div class="flex flex-wrap justify-end gap-2">
                        <flux:button wire:click="goToStep(2)" variant="filled" icon="clipboard-document-list">
                            ดูรายการผลงาน
                        </flux:button>
                        @if ($workRecord)
                            <flux:button :href="route('staff.records.print', $workRecord)" variant="primary" icon="printer" target="_blank">
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
                </div>
            @endunless
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
                        เพิ่มผลงาน
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
                :readonly="$readOnly"
                :modal-entry="$modalEntry"
                :evidence-uploads="$evidenceUploads"
            />

            <div class="flex justify-between gap-2">
                @if (! $readOnly && $modalEntryIndex !== null)
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
                        {{ $readOnly ? 'ปิด' : 'ยกเลิก' }}
                    </flux:button>
                    @unless ($readOnly)
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
</div>
