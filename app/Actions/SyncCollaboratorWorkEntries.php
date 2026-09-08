<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Enums\WorkRecordStatus;
use App\Models\User;
use App\Models\WorkEntry;
use App\Models\WorkRecord;

class SyncCollaboratorWorkEntries
{
    /**
     * @param  list<int>  $collaboratorUserIds
     * @return array{synced: list<int>, skipped: list<array{id: int, name: string}>}
     */
    public function __invoke(WorkEntry $sourceEntry, array $collaboratorUserIds): array
    {
        $sourceEntry->loadMissing(['workRecord', 'evidences', 'mirroredEntries.workRecord']);

        $ownerUserId = $sourceEntry->workRecord->user_id;
        $periodId = $sourceEntry->workRecord->evaluation_period_id;

        $ids = collect($collaboratorUserIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->filter(fn (int $id) => $id !== $ownerUserId)
            ->values();

        $staffUsers = User::query()
            ->whereIn('id', $ids)
            ->where('role', UserRole::Staff)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->keyBy('id');

        $synced = [];
        $skipped = [];
        $keptMirrorRecordIds = [];

        foreach ($ids as $userId) {
            $user = $staffUsers->get($userId);

            if (! $user) {
                continue;
            }

            $record = WorkRecord::firstOrCreate(
                ['user_id' => $userId, 'evaluation_period_id' => $periodId],
                ['status' => WorkRecordStatus::Draft],
            );

            if (! $record->isEditable()) {
                $skipped[] = ['id' => $user->id, 'name' => $user->name];

                continue;
            }

            $mirror = WorkEntry::updateOrCreate(
                [
                    'source_work_entry_id' => $sourceEntry->id,
                    'work_record_id' => $record->id,
                ],
                [
                    'workload_id' => $sourceEntry->workload_id,
                    'detail' => $sourceEntry->detail,
                    'result' => $sourceEntry->result,
                    'hours' => $sourceEntry->hours,
                    'sort_order' => $sourceEntry->sort_order,
                ],
            );

            $this->syncEvidences($sourceEntry, $mirror);

            $synced[] = $user->id;
            $keptMirrorRecordIds[] = $record->id;
        }

        WorkEntry::query()
            ->where('source_work_entry_id', $sourceEntry->id)
            ->when(
                $keptMirrorRecordIds !== [],
                fn ($query) => $query->whereNotIn('work_record_id', $keptMirrorRecordIds),
                fn ($query) => $query,
            )
            ->get()
            ->each(fn (WorkEntry $entry) => $entry->delete());

        return ['synced' => $synced, 'skipped' => $skipped];
    }

    protected function syncEvidences(WorkEntry $source, WorkEntry $mirror): void
    {
        $source->loadMissing('evidences');

        $mirror->evidences()->get()->each->delete();

        foreach ($source->evidences as $evidence) {
            $mirror->evidences()->create([
                'type' => $evidence->type,
                'value' => $evidence->value,
                'filename' => $evidence->filename,
                'sort_order' => $evidence->sort_order,
            ]);
        }
    }
}
