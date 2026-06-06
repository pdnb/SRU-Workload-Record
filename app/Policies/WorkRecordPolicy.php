<?php

namespace App\Policies;

use App\Enums\PeriodStatus;
use App\Enums\WorkRecordStatus;
use App\Models\User;
use App\Models\WorkRecord;

class WorkRecordPolicy
{
    public function view(User $user, WorkRecord $record): bool
    {
        if ($record->user_id === $user->id) {
            return true;
        }

        if ($user->isSupervisor() && $record->user->department === $user->department) {
            return true;
        }

        return $user->isHrOrAdmin();
    }

    public function update(User $user, WorkRecord $record): bool
    {
        return $record->user_id === $user->id
            && in_array($record->status, [WorkRecordStatus::Draft, WorkRecordStatus::Rejected])
            && $record->evaluationPeriod?->status === PeriodStatus::Open;
    }

    public function submit(User $user, WorkRecord $record): bool
    {
        return $this->update($user, $record);
    }

    public function approve(User $user, WorkRecord $record): bool
    {
        if ($record->status !== WorkRecordStatus::Submitted) {
            return false;
        }

        if ($user->isHrOrAdmin()) {
            return true;
        }

        return $user->isSupervisor() && $record->user->department === $user->department;
    }

    public function reject(User $user, WorkRecord $record): bool
    {
        return $this->approve($user, $record);
    }

    public function delete(User $user, WorkRecord $record): bool
    {
        return $record->user_id === $user->id && $record->status === WorkRecordStatus::Draft;
    }
}
