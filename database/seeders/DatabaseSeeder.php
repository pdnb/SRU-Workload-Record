<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            FiscalYearSeeder::class,
            PositionSeeder::class,
            WorkloadStructureSeeder::class,
            UserSeeder::class,
            // WorkRecordSeeder::class,
            // WorkEntrySeeder::class,
        ]);
    }
}
