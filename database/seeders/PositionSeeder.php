<?php

namespace Database\Seeders;

use App\Models\Position;
use Illuminate\Database\Seeder;

class PositionSeeder extends Seeder
{
    public const COMPUTER_OFFICER_CODE = '0901';

    public function run(): void
    {
        Position::firstOrCreate(
            ['code' => self::COMPUTER_OFFICER_CODE],
            ['name' => 'นักวิชาการคอมพิวเตอร์-ปฏิบัติการ', 'is_active' => true],
        );
    }
}
