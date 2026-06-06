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
            'email' => 'admin@sru.ac.th',
            'password' => Hash::make('P@$$w0rd'),
            ...$verified,
            'role' => UserRole::Admin,
        ]);

        User::create([
            'name' => 'หัวหน้างานเทคโนโลยี',
            'email' => 'supervisor@sru.ac.th',
            'password' => Hash::make('P@$$w0rd'),
            ...$verified,
            'role' => UserRole::Supervisor,
        ]);
    }
}
