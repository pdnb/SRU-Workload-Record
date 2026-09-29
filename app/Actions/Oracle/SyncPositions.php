<?php

namespace App\Actions\Oracle;

use App\Contracts\WorkloadSourceReader;
use App\Models\Position;

class SyncPositions
{
    public function __construct(
        private WorkloadSourceReader $reader,
    ) {}

    /**
     * Upserts positions by external_id. Positions missing from the source are
     * deactivated, never deleted, because users and structures reference them.
     * A local position with the same code is adopted rather than duplicated.
     *
     * @return array{created: int, updated: int, unchanged: int, deactivated: int}
     */
    public function __invoke(): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0];
        $seen = [];
        $now = now();

        foreach ($this->reader->positions() as $row) {
            $seen[] = $row->externalId;

            $position = Position::where('external_id', $row->externalId)->first()
                ?? Position::whereNull('external_id')->where('code', $row->code)->first()
                ?? new Position;

            $position->fill([
                'external_id' => $row->externalId,
                'code' => $row->code,
                'name' => $row->name,
                'is_active' => $row->active,
            ]);

            $stats[match (true) {
                ! $position->exists => 'created',
                $position->isDirty() => 'updated',
                default => 'unchanged',
            }]++;

            $position->synced_at = $now;
            $position->save();
        }

        // An empty source is far more likely a mapping/permission problem than
        // "every position was abolished" — never mass-deactivate on it.
        if ($seen === []) {
            return $stats;
        }

        $stats['deactivated'] = Position::query()
            ->whereNotNull('external_id')
            ->whereNotIn('external_id', $seen)
            ->where('is_active', true)
            ->update(['is_active' => false, 'synced_at' => $now]);

        return $stats;
    }
}
