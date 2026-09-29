<?php

namespace Database\Seeders;

use App\Enums\WorkRecordStatus;
use App\Models\WorkRecord;
use Illuminate\Database\Seeder;

class WorkRecordSeeder extends Seeder
{
    public function run(): void
    {
        $records = [
            [
                'id' => 1,
                'user_id' => 3,
                'evaluation_period_id' => 4,
                'status' => WorkRecordStatus::Draft,
                'self_assessment' => '',
                'self_score' => null,
                'submitted_at' => null,
                'approved_at' => null,
                'rejection_comment' => null,
                'created_at' => '2026-06-08 04:41:27',
                'updated_at' => '2026-06-08 04:41:27',
            ],
            [
                'id' => 2,
                'user_id' => 4,
                'evaluation_period_id' => 4,
                'status' => WorkRecordStatus::Rejected,
                'self_assessment' => '',
                'self_score' => null,
                'submitted_at' => '2026-06-08 09:11:10',
                'approved_at' => null,
                'rejection_comment' => 'ไม่ระบุ',
                'created_at' => '2026-06-08 09:06:04',
                'updated_at' => '2026-06-10 01:56:13',
            ],
            [
                'id' => 3,
                'user_id' => 7,
                'evaluation_period_id' => 4,
                'status' => WorkRecordStatus::Draft,
                'self_assessment' => '',
                'self_score' => null,
                'submitted_at' => null,
                'approved_at' => null,
                'rejection_comment' => null,
                'created_at' => '2026-06-23 02:27:04',
                'updated_at' => '2026-06-23 02:27:04',
            ],
            [
                'id' => 4,
                'user_id' => 5,
                'evaluation_period_id' => 4,
                'status' => WorkRecordStatus::Draft,
                'self_assessment' => '',
                'self_score' => null,
                'submitted_at' => null,
                'approved_at' => null,
                'rejection_comment' => null,
                'created_at' => '2026-06-23 03:05:20',
                'updated_at' => '2026-06-23 03:05:20',
            ],
        ];

        WorkRecord::unguard();
        foreach ($records as $record) {
            $created = WorkRecord::create($record);
            $created->update(['workload_structure_id' => $created->resolvedStructureId()]);
        }
        WorkRecord::reguard();
    }
}
