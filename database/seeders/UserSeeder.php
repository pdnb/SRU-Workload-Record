<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $verified = ['email_verified_at' => now()];

        User::create([
            'name' => 'ผู้ดูแลระบบ',
            'email' => 'admin@university.ac.th',
            'password' => Hash::make('password'),
            ...$verified,
            'position' => 'เจ้าหน้าที่บริหารงานทั่วไป',
            'department' => 'งานเทคโนโลยีสารสนเทศ',
            'faculty' => 'สำนักคอมพิวเตอร์',
            'role' => UserRole::Admin,
        ]);

        User::create([
            'name' => 'หัวหน้างานเทคโนโลยี',
            'email' => 'supervisor@university.ac.th',
            'password' => Hash::make('password'),
            ...$verified,
            'position' => 'หัวหน้างานเทคโนโลยีสารสนเทศ',
            'department' => 'งานเทคโนโลยีสารสนเทศ',
            'faculty' => 'สำนักคอมพิวเตอร์',
            'role' => UserRole::Supervisor,
        ]);

        foreach (range(1, 3) as $i) {
            User::create([
                'name' => "เจ้าหน้าที่ {$i}",
                'email' => "staff{$i}@university.ac.th",
                'password' => Hash::make('password'),
                ...$verified,
                'position' => 'นักวิชาการคอมพิวเตอร์',
                'department' => 'งานเทคโนโลยีสารสนเทศ',
                'faculty' => 'สำนักคอมพิวเตอร์',
                'role' => UserRole::Staff,
            ]);
        }
    }
}
