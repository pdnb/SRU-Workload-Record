<?php

namespace App\Actions\Oracle;

use App\Contracts\WorkloadSourceReader;
use App\Data\Oracle\StaffPositionRow;
use App\Enums\PositionSource;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Str;

class SyncStaffPositions
{
    public function __construct(
        private WorkloadSourceReader $reader,
    ) {}

    /**
     * Sets users' positions from the source. Users are matched by employee id
     * (users.external_id) first, then by email. Positions an admin set by hand
     * (position_source = manual) are never overwritten.
     *
     * @return array{updated: int, unchanged: int, skipped_manual: int, unmatched: int, unknown_position: int}
     */
    public function __invoke(): array
    {
        $stats = ['updated' => 0, 'unchanged' => 0, 'skipped_manual' => 0, 'unmatched' => 0, 'unknown_position' => 0];
        $positionIds = Position::pluck('id', 'code');

        foreach ($this->latestPerStaff($this->reader->staffPositions()) as $row) {
            $user = $this->findUser($row);

            if ($user === null) {
                $stats['unmatched']++;

                continue;
            }

            $stats[$this->apply($user, $row, $positionIds->all())]++;
        }

        return $stats;
    }

    /**
     * Refreshes one user's position (used right after login). Returns false when
     * the source has no row for the user.
     */
    public function forUser(User $user): bool
    {
        $row = $this->reader->staffPositionFor($user->external_id, $user->email);

        if ($row === null) {
            return false;
        }

        $this->apply($user, $row, Position::pluck('id', 'code')->all());

        return true;
    }

    /**
     * @param  array<string, int>  $positionIds  code => id
     * @return 'updated'|'unchanged'|'skipped_manual'|'unknown_position'
     */
    protected function apply(User $user, StaffPositionRow $row, array $positionIds): string
    {
        if ($user->external_id === null && filled($row->employeeId)
            && ! User::where('external_id', $row->employeeId)->exists()) {
            $user->external_id = $row->employeeId;
        }

        if ($user->position_source === PositionSource::Manual) {
            $user->save();

            return 'skipped_manual';
        }

        $positionId = $positionIds[$row->positionCode] ?? null;

        if ($positionId === null) {
            $user->save();

            return 'unknown_position';
        }

        $user->position_id = $positionId;
        $user->position_source = PositionSource::Oracle;
        $changed = $user->isDirty('position_id');
        $user->position_synced_at = now();
        $user->save();

        return $changed ? 'updated' : 'unchanged';
    }

    protected function findUser(StaffPositionRow $row): ?User
    {
        if (filled($row->employeeId) && $user = User::where('external_id', $row->employeeId)->first()) {
            return $user;
        }

        foreach (array_filter([$row->email, $row->altEmail]) as $email) {
            if ($user = User::whereRaw('LOWER(email) = ?', [Str::lower($email)])->first()) {
                return $user;
            }
        }

        return null;
    }

    /**
     * When the source lists a staff member once per fiscal year, keep the newest.
     *
     * @param  iterable<StaffPositionRow>  $rows
     * @return list<StaffPositionRow>
     */
    protected function latestPerStaff(iterable $rows): array
    {
        $latest = [];

        foreach ($rows as $row) {
            $key = $row->employeeId ?? $row->email;

            if ($key === null) {
                continue;
            }

            if (! isset($latest[$key]) || ($row->fiscalYear ?? 0) >= ($latest[$key]->fiscalYear ?? 0)) {
                $latest[$key] = $row;
            }
        }

        return array_values($latest);
    }
}
