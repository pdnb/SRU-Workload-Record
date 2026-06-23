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
        $password = Hash::make('P@$$w0rd');

        $users = [
            [
                'id' => 1,
                'name' => 'ผู้ดูแลระบบ',
                'email' => 'admin@sru.ac.th',
                'email_verified_at' => '2026-06-08 04:39:25',
                'password' => $password,
                'oauth_sub' => null,
                'position' => null,
                'department' => null,
                'faculty' => null,
                'role' => UserRole::Admin,
                'remember_token' => null,
                'created_at' => '2026-06-08 04:39:26',
                'updated_at' => '2026-06-08 04:39:26',
            ],
            [
                'id' => 2,
                'name' => 'หัวหน้างานเทคโนโลยี',
                'email' => 'supervisor@sru.ac.th',
                'email_verified_at' => '2026-06-08 04:39:25',
                'password' => $password,
                'oauth_sub' => null,
                'position' => null,
                'department' => null,
                'faculty' => null,
                'role' => UserRole::Staff,
                'remember_token' => null,
                'created_at' => '2026-06-08 04:39:26',
                'updated_at' => '2026-06-08 04:39:26',
            ],
            [
                'id' => 3,
                'name' => 'กีรติ อินทฤกษ์',
                'email' => 'keerati.int@sru.ac.th',
                'email_verified_at' => null,
                'password' => $password,
                'oauth_sub' => '1783',
                'position' => null,
                'department' => null,
                'faculty' => null,
                'role' => UserRole::Staff,
                'remember_token' => 'ghhVjgCKtsub25RCPv1bnV5gn284FBzgPLAoBiNYuFtShjKwcuemzt23ry6W',
                'created_at' => '2026-06-08 04:40:23',
                'updated_at' => '2026-06-08 04:40:23',
            ],
            [
                'id' => 4,
                'name' => 'นิธิ ลอยชูศักดิ์',
                'email' => 'nithi@sru.ac.th',
                'email_verified_at' => null,
                'password' => null,
                'oauth_sub' => '769',
                'position' => null,
                'department' => null,
                'faculty' => null,
                'role' => UserRole::Staff,
                'remember_token' => 'DRov1TjPbJAf9PlBt4eFcrigQhxWfLsUjN6coD1fvNrocgBoT0y0h5dkjyzD',
                'created_at' => '2026-06-08 08:22:39',
                'updated_at' => '2026-06-08 08:22:39',
            ],
            [
                'id' => 5,
                'name' => 'ธนกฤต เจริญพานิช',
                'email' => 'thanakrit.cha@sru.ac.th',
                'email_verified_at' => null,
                'password' => null,
                'oauth_sub' => '2519',
                'position' => null,
                'department' => null,
                'faculty' => null,
                'role' => UserRole::Staff,
                'remember_token' => 'UWXwHnFWC1C7K6DXLk1R1NHzkQNM04MlO2umKg10bHW02M0mOZMXGmpnBnSR',
                'created_at' => '2026-06-08 08:37:29',
                'updated_at' => '2026-06-08 08:37:29',
            ],
            [
                'id' => 6,
                'name' => 'อิสริช พงษ์ธนู',
                'email' => 'isarich@sru.ac.th, isarich2@sru.ac.th',
                'email_verified_at' => null,
                'password' => null,
                'oauth_sub' => '2383',
                'position' => null,
                'department' => null,
                'faculty' => null,
                'role' => UserRole::Staff,
                'remember_token' => 'ASYdLRjak1aIib7mVfPrg1d8Z7Iv8Mv85ThyfcKvFt2WrWBnfcDxRvYWvwhR',
                'created_at' => '2026-06-23 01:49:30',
                'updated_at' => '2026-06-23 01:49:30',
            ],
            [
                'id' => 7,
                'name' => 'ยุทธพงษ์ ผลจันทร์',
                'email' => 'yuttapong.pho@sru.ac.th',
                'email_verified_at' => null,
                'password' => null,
                'oauth_sub' => '576',
                'position' => null,
                'department' => null,
                'faculty' => null,
                'role' => UserRole::Staff,
                'remember_token' => 'kQ9rN2wIZsG1l1p58Z2LO93sAe2QVeLbU80NjSc4iKWVQqXmyewljzepA0Ot',
                'created_at' => '2026-06-23 02:26:15',
                'updated_at' => '2026-06-23 02:26:15',
            ],
        ];

        User::unguard();
        foreach ($users as $user) {
            User::create($user);
        }
        User::reguard();
    }
}
